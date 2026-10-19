<?php
namespace App\Services\Pos;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Discounts;
use App\Services\Inventory;
use App\Services\Items;
use App\Services\Ledger;
use App\Services\Sequence;
use App\Services\Settings;

/**
 * Sales ("tickets") at the POS.
 * Flow: scan / tap items -> (hold the sale for later, or) discount -> pay.
 * Open tickets are held sales. Paying assigns the receipt number, deducts stock (first-expiry lots first)
 * and posts the sale to the GL. Lines may be sold in pack units (1 box of 100 tablets); stock moves in base units.
 */
class Tickets
{
    /** A ticket with its lines, payments and returns (sc_details decoded). */
    public static function get(int $id): array
    {
        $t = DB::one(
            'SELECT t.*, u.full_name AS created_by_name, pu.full_name AS paid_by_name, vu.full_name AS voided_by_name, c.name AS customer_account_name
             FROM tickets t LEFT JOIN users u ON u.id = t.created_by LEFT JOIN users pu ON pu.id = t.paid_by
             LEFT JOIN users vu ON vu.id = t.voided_by LEFT JOIN customers c ON c.id = t.customer_id WHERE t.id = ?', [$id]
        );
        if (!$t) throw HttpException::notFound('Sale');
        $t['items'] = DB::all('SELECT ti.*, i.rx_required, i.generic_name FROM ticket_items ti LEFT JOIN items i ON i.id = ti.item_id WHERE ti.ticket_id = ? ORDER BY ti.id', [$id]);
        $t['payments'] = DB::all('SELECT * FROM payments WHERE ticket_id = ? ORDER BY id', [$id]);
        $t['returns'] = $t['status'] === 'paid' || (float) $t['refunded_total'] > 0 ? Returns::listForTicket($id) : [];
        $t['sc_details'] = json_decode($t['sc_details'] ?? '[]', true) ?: [];
        return self::numeric($t);
    }

    /** Cast NUMERIC strings to numbers so the POS JavaScript gets real numbers. */
    private static function numeric(array $t): array
    {
        $money = ['subtotal', 'discount_rate', 'discount_amount', 'sc_discount', 'promo_discount', 'vatable_sales', 'vat_amount', 'vat_exempt_sales', 'total', 'paid_total', 'change_amount', 'cogs', 'refunded_total'];
        foreach ($money as $k) $t[$k] = (float) $t[$k];
        foreach (['id', 'sc_count', 'cash_session_id', 'customer_id'] as $k) $t[$k] = $t[$k] === null ? null : (int) $t[$k];
        foreach ($t['items'] as &$i) {
            foreach (['qty', 'price', 'line_total', 'discount_value', 'discount_amount', 'factor', 'unit_cost', 'returned_qty'] as $k) $i[$k] = (float) $i[$k];
            $i['id'] = (int) $i['id']; $i['item_id'] = (int) $i['item_id']; $i['vat_exempt'] = (int) $i['vat_exempt']; $i['rx_required'] = (int) ($i['rx_required'] ?? 0);
            $i['uom_id'] = $i['uom_id'] === null ? null : (int) $i['uom_id'];
        }
        foreach ($t['payments'] as &$p) {
            foreach (['amount', 'tendered', 'change_amount'] as $k) $p[$k] = $p[$k] === null ? null : (float) $p[$k];
        }
        return $t;
    }

    public static function getOpen(int $id): array
    {
        $t = self::get($id);
        if ($t['status'] !== 'open') throw HttpException::bad("Sale {$t['ticket_no']} is already {$t['status']}");
        return $t;
    }

    /** Held (open) sales for the POS. */
    public static function openList(): array
    {
        return array_map(fn ($r) => ['total' => (float) $r['total'], 'item_count' => (float) $r['item_count'], 'id' => (int) $r['id']] + $r, DB::all(
            "SELECT t.id, t.ticket_no, t.customer_name, t.total, t.created_at, t.notes, u.full_name AS created_by_name,
                    (SELECT COALESCE(SUM(i.qty),0) FROM ticket_items i WHERE i.ticket_id = t.id AND i.status = 'active') AS item_count
             FROM tickets t LEFT JOIN users u ON u.id = t.created_by WHERE t.status = 'open' ORDER BY t.id"
        ));
    }

    public static function recalc(int $id): array
    {
        $t = DB::one('SELECT * FROM tickets WHERE id = ?', [$id]);
        $lines = DB::all('SELECT * FROM ticket_items WHERE ticket_id = ?', [$id]);
        $totals = Totals::compute($t, $lines);
        $lineDiscounts = $totals['line_discounts'];
        $extra = ['line_discounts' => $lineDiscounts, 'line_net' => $totals['line_net'], 'line_vat' => $totals['line_vat'], 'discount_net' => $totals['discount_net']];
        unset($totals['line_discounts'], $totals['line_net'], $totals['line_vat'], $totals['discount_net']);
        DB::update('tickets', $id, $totals);
        foreach ($lines as $l) {
            $amount = $l['status'] === 'active' ? ($lineDiscounts[(int) $l['id']] ?? 0.0) : 0.0;
            if (r2($l['discount_amount']) != $amount) DB::update('ticket_items', (int) $l['id'], ['discount_amount' => $amount]);
        }
        return $totals + $extra;
    }

    public static function create(array $data): array
    {
        $s = CashSessions::requireOpen();
        $id = DB::insert('tickets', [
            'ticket_no' => Sequence::next('TKT', 'T', 6), 'cash_session_id' => $s['id'], 'business_date' => $s['business_date'],
            'customer_name' => trim((string) ($data['customer_name'] ?? '')) ?: null, 'customer_id' => !empty($data['customer_id']) ? (int) $data['customer_id'] : null,
            'status' => 'open', 'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'created_by' => Auth::id(), 'created_at' => now(),
        ]);
        return self::get($id);
    }

    public static function update(int $id, array $data): array
    {
        self::getOpen($id);
        $upd = [];
        if (array_key_exists('customer_name', $data)) $upd['customer_name'] = trim((string) $data['customer_name']) ?: null;
        if (array_key_exists('customer_id', $data)) $upd['customer_id'] = $data['customer_id'] ? (int) $data['customer_id'] : null;
        if (array_key_exists('notes', $data)) $upd['notes'] = trim((string) $data['notes']) ?: null;
        if (array_key_exists('rx_note', $data)) $upd['rx_note'] = trim((string) $data['rx_note']) ?: null;
        DB::update('tickets', $id, $upd);
        self::recalc($id);
        return self::get($id);
    }

    /** Unit the line is sold in: base unit, or a pack unit of the item (1 box = 100 tab) with its own price. */
    private static function unitFor(array $item, $uomId): array
    {
        $base = ['uom_id' => null, 'factor' => 1.0, 'label' => (string) DB::value('SELECT abbr FROM uoms WHERE id = ?', [$item['base_uom_id']]), 'price' => null];
        if (!$uomId || (int) $uomId === (int) $item['base_uom_id']) return $base;
        $p = DB::one('SELECT iu.*, u.abbr FROM item_uoms iu JOIN uoms u ON u.id = iu.uom_id WHERE iu.item_id = ? AND iu.uom_id = ?', [$item['id'], (int) $uomId]);
        if (!$p) throw HttpException::bad("{$item['name']} is not sold in that unit");
        return ['uom_id' => (int) $p['uom_id'], 'factor' => (float) $p['factor'], 'label' => $p['abbr'], 'price' => $p['price'] === null ? r2($item['price'] * $p['factor']) : (float) $p['price']];
    }

    /** Price per sold unit: pack price, or the base price (wholesale from the minimum quantity up). */
    private static function autoPrice(array $item, array $unit, float $qty): float
    {
        if ($unit['uom_id'] !== null) return (float) $unit['price'];
        return Items::effectivePrice($item, $qty * $unit['factor']);
    }

    /** Refuse to sell more than is on hand when the setting says so. */
    private static function checkStock(array $item, float $baseQtyAfter): void
    {
        if (!Settings::pos()['blockNegativeStock'] || !Inventory::isStocked($item)) return;
        if ($baseQtyAfter > (float) $item['stock_qty'] + 0.00005) {
            throw HttpException::bad("Only " . qty($item['stock_qty']) . " of {$item['name']} in stock");
        }
    }

    /**
     * Add an item. $uomId picks a pack unit; $price overrides the price (needs pos.discount or a manager PIN).
     * The same item, unit and price (without a line discount) merges into one line.
     */
    public static function addItem(int $id, int $itemId, float $qty = 1, ?string $notes = null, $price = null, ?string $pin = null, $uomId = null): array
    {
        $t = self::getOpen($id);
        $item = Inventory::item($itemId);
        if (!$item['active'] || !$item['sellable']) throw HttpException::bad("{$item['name']} is not available for sale");
        if (!($qty > 0)) throw HttpException::bad('Quantity must be greater than zero');
        $unit = self::unitFor($item, $uomId);
        $notes = trim((string) $notes) ?: null;
        $override = $price !== null && $price !== '';
        $existing = DB::one(
            "SELECT * FROM ticket_items WHERE ticket_id = ? AND item_id = ? AND status = 'active' AND COALESCE(uom_id, 0) = ? AND COALESCE(notes,'') = ? AND discount_kind IS NULL"
            . ($override ? ' AND price = ?' : '') . ' ORDER BY id LIMIT 1',
            array_merge([$id, $itemId, (int) ($unit['uom_id'] ?? 0), $notes ?? ''], $override ? [r2($price)] : [])
        );
        $newQty = ($existing ? (float) $existing['qty'] : 0) + $qty;
        if ($override) {
            if (r2($price) != self::autoPrice($item, $unit, $newQty)) Auth::authorize('pos.discount', $pin);   // price override needs discount rights
            $unitPrice = r2($price);
        } else {
            $unitPrice = self::autoPrice($item, $unit, $newQty);
            // A line whose price was overridden keeps its price; only an automatically priced line follows the wholesale rule
            if ($existing && r2($existing['price']) != self::autoPrice($item, $unit, (float) $existing['qty'])) $unitPrice = (float) $existing['price'];
        }
        $otherBase = (float) DB::value("SELECT COALESCE(SUM(qty * factor),0) FROM ticket_items WHERE ticket_id = ? AND item_id = ? AND status = 'active' AND id <> ?", [$id, $itemId, (int) ($existing['id'] ?? 0)]);
        self::checkStock($item, $otherBase + $newQty * $unit['factor']);

        if ($existing) {
            DB::update('ticket_items', (int) $existing['id'], ['qty' => $newQty, 'price' => $unitPrice, 'line_total' => r2($newQty * $unitPrice)]);
        } else {
            DB::insert('ticket_items', [
                'ticket_id' => $id, 'item_id' => $itemId, 'name' => $item['name'], 'qty' => $qty, 'uom_id' => $unit['uom_id'], 'uom_label' => $unit['label'],
                'factor' => $unit['factor'], 'price' => $unitPrice, 'line_total' => r2($qty * $unitPrice), 'vat_exempt' => (int) $item['vat_exempt'],
                'notes' => $notes, 'status' => 'active', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
        }
        self::recalc($id);
        return self::get($id);
    }

    private static function line(int $ticketId, int $lineId): array
    {
        $l = DB::one("SELECT * FROM ticket_items WHERE id = ? AND ticket_id = ? AND status = 'active'", [$lineId, $ticketId]);
        if (!$l) throw HttpException::notFound('Sale line');
        return $l;
    }

    /** Change quantity / notes / price of a line. A price change needs pos.discount or a manager PIN. */
    public static function updateLine(int $id, int $lineId, array $data, ?string $pin = null): array
    {
        self::getOpen($id);
        $l = self::line($id, $lineId);
        $item = Inventory::item((int) $l['item_id']);
        $qty = isset($data['qty']) && $data['qty'] !== '' ? (float) $data['qty'] : (float) $l['qty'];
        if (!($qty > 0)) throw HttpException::bad('Quantity must be greater than zero');
        $unit = ['uom_id' => $l['uom_id'] ? (int) $l['uom_id'] : null, 'factor' => (float) $l['factor'], 'price' => null];
        if ($unit['uom_id']) $unit = self::unitFor($item, $unit['uom_id']);
        $wasAuto = r2($l['price']) == self::autoPrice($item, $unit, (float) $l['qty']);
        $price = $wasAuto ? self::autoPrice($item, $unit, $qty) : (float) $l['price'];
        if (isset($data['price']) && $data['price'] !== '' && r2($data['price']) != $price) {
            if (r2($data['price']) != self::autoPrice($item, $unit, $qty)) Auth::authorize('pos.discount', $pin);
            $price = r2($data['price']);
        }
        $otherBase = (float) DB::value("SELECT COALESCE(SUM(qty * factor),0) FROM ticket_items WHERE ticket_id = ? AND item_id = ? AND status = 'active' AND id <> ?", [$id, $item['id'], $lineId]);
        self::checkStock($item, $otherBase + $qty * $unit['factor']);
        $upd = ['qty' => $qty, 'price' => $price, 'line_total' => r2($qty * $price)];
        if (array_key_exists('notes', $data)) $upd['notes'] = trim((string) $data['notes']) ?: null;
        DB::update('ticket_items', (int) $l['id'], $upd);
        self::recalc($id);
        return self::get($id);
    }

    /** Remove a line from an open sale. */
    public static function voidLine(int $id, int $lineId, ?string $reason = null, ?string $pin = null): array
    {
        self::getOpen($id);
        $l = self::line($id, $lineId);
        DB::run('DELETE FROM ticket_items WHERE id = ?', [$lineId]);
        self::syncScPeople($id);
        self::recalc($id);
        Audit::log('remove_item', 'ticket', $id, ['line' => $l['name'], 'qty' => $l['qty']]);
        return self::get($id);
    }

    /**
     * Discount on the whole receipt. $d = ['discount_id' => preset id (+ 'value' for open presets)]
     * or the plain form ['discount_type' => none|sc|pwd|percent|amount, 'discount_rate' => n].
     * SC/PWD also needs 'sc_details' => [['name' => .., 'id_no' => ..], ...] (the whole receipt is the senior's purchase).
     * Presets marked "requires approval" need pos.discount or a manager PIN.
     */
    public static function discount(int $id, array $d, ?string $pin = null): array
    {
        $t = self::getOpen($id);
        $disc = Discounts::resolve($d, 'order');
        $hasItemSc = (bool) array_filter($t['items'], fn ($i) => $i['status'] === 'active' && in_array($i['discount_kind'], ['sc', 'pwd'], true));
        if (!$disc) {
            $upd = ['discount_type' => 'none', 'discount_id' => null, 'discount_name' => null, 'discount_rate' => 0];
            if (!$hasItemSc) $upd += ['sc_count' => 0, 'sc_details' => null];
            DB::update('tickets', $id, $upd);
            self::recalc($id);
            Audit::log('discount', 'ticket', $id, ['type' => 'none']);
            return self::get($id);
        }
        if ($disc['requires_approval']) Auth::authorize('pos.discount', $pin);
        $upd = ['discount_type' => $disc['kind'], 'discount_id' => $disc['id'], 'discount_name' => $disc['name'], 'discount_rate' => 0];
        if ($disc['kind'] === 'sc' || $disc['kind'] === 'pwd') {
            if (array_filter($t['items'], fn ($i) => $i['status'] === 'active' && $i['discount_kind'])) {
                throw HttpException::bad('Remove the item discounts first: a Senior Citizen / PWD discount on the whole receipt cannot be combined with other discounts.');
            }
            $people = self::people($d['sc_details'] ?? []);
            if (!$people) throw HttpException::bad('Enter the name and ID number of the Senior Citizen / PWD');
            $upd['sc_count'] = count($people);
            $upd['sc_details'] = json_encode($people, JSON_UNESCAPED_UNICODE);
        } else {
            if (!$hasItemSc) $upd += ['sc_count' => 0, 'sc_details' => null];   // keep names captured for SC/PWD items
            $rate = (float) $disc['value'];
            if (!($rate > 0)) throw HttpException::bad('Enter the discount value');
            if ($disc['kind'] === 'percent' && $rate > 100) throw HttpException::bad('Discount cannot exceed 100%');
            $upd['discount_rate'] = $rate;
        }
        DB::update('tickets', $id, $upd);
        self::recalc($id);
        Audit::log('discount', 'ticket', $id, ['name' => $disc['name'], 'type' => $disc['kind'], 'rate' => $upd['discount_rate'], 'sc_count' => $upd['sc_count'] ?? null]);
        return self::get($id);
    }

    /**
     * Discount on a single sale line. $d like discount() (preset id or kind/value); kind 'none' removes it.
     * For SC/PWD on an item, pass 'sc_person' => ['name' => .., 'id_no' => ..] unless the sale already has one.
     */
    public static function discountLine(int $id, int $lineId, array $d, ?string $pin = null): array
    {
        $t = self::getOpen($id);
        $l = self::line($id, $lineId);
        $disc = Discounts::resolve($d, 'item');
        if (!$disc) {
            DB::update('ticket_items', $lineId, ['discount_id' => null, 'discount_name' => null, 'discount_kind' => null, 'discount_value' => 0, 'discount_amount' => 0]);
            self::syncScPeople($id);
            self::recalc($id);
            Audit::log('discount_item', 'ticket', $id, ['line' => $l['name'], 'type' => 'none']);
            return self::get($id);
        }
        if (in_array($t['discount_type'], ['sc', 'pwd'], true)) {
            throw HttpException::bad('This receipt already has a Senior Citizen / PWD discount on the whole receipt. Remove it first to discount single items.');
        }
        if ($disc['requires_approval']) Auth::authorize('pos.discount', $pin);
        $value = (float) $disc['value'];
        if ($disc['kind'] === 'sc' || $disc['kind'] === 'pwd') {
            $people = self::people($t['sc_details']);
            $new = self::people(isset($d['sc_person']) ? [$d['sc_person']] : []);
            foreach ($new as $p) {
                if (!in_array($p['id_no'], array_column($people, 'id_no'), true)) $people[] = $p;
            }
            if (!$people) throw HttpException::bad('Enter the name and ID number of the Senior Citizen / PWD');
            DB::update('tickets', $id, ['sc_details' => json_encode($people, JSON_UNESCAPED_UNICODE), 'sc_count' => count($people)]);
            $value = 0;
        } else {
            if (!($value > 0)) throw HttpException::bad('Enter the discount value');
            if ($disc['kind'] === 'percent' && $value > 100) throw HttpException::bad('Discount cannot exceed 100%');
        }
        DB::update('ticket_items', $lineId, ['discount_id' => $disc['id'], 'discount_name' => $disc['name'], 'discount_kind' => $disc['kind'], 'discount_value' => $value]);
        self::recalc($id);
        Audit::log('discount_item', 'ticket', $id, ['line' => $l['name'], 'name' => $disc['name'], 'type' => $disc['kind'], 'value' => $value]);
        return self::get($id);
    }

    /** Clean list of [name, id_no] pairs; both are required. */
    private static function people($list): array
    {
        if (is_string($list)) $list = json_decode($list, true) ?: [];
        $out = [];
        foreach ((array) $list as $p) {
            if (!is_array($p)) continue;
            $name = trim((string) ($p['name'] ?? ''));
            $idNo = trim((string) ($p['id_no'] ?? ''));
            if ($name === '' && $idNo === '') continue;
            if ($name === '' || $idNo === '') throw HttpException::bad('Name and ID number are required for each Senior Citizen / PWD');
            $out[] = ['name' => $name, 'id_no' => $idNo];
        }
        return $out;
    }

    /** When the last SC/PWD item discount is removed, forget the names (unless the whole receipt is SC/PWD). */
    private static function syncScPeople(int $id): void
    {
        $t = DB::one('SELECT discount_type FROM tickets WHERE id = ?', [$id]);
        if (in_array($t['discount_type'], ['sc', 'pwd'], true)) return;
        $tagged = DB::value("SELECT COUNT(*) FROM ticket_items WHERE ticket_id = ? AND status = 'active' AND discount_kind IN ('sc','pwd')", [$id]);
        if (!$tagged) DB::update('tickets', $id, ['sc_details' => null, 'sc_count' => 0]);
    }

    /**
     * Complete a sale with one or more payments:
     *   [['method' => 'cash', 'amount' => 500], ['method' => 'card', 'amount' => 200, 'reference' => 'APP123'],
     *    ['method' => 'charge', 'amount' => 300, 'customer_id' => 1]]
     * Cash may exceed the balance (change is given); non-cash payments may not.
     * $extra: rx_note (prescription / doctor for Rx items), customer_name.
     */
    public static function pay(int $id, array $payments, array $extra = []): array
    {
        $t = self::getOpen($id);
        $session = CashSessions::requireOpen();
        $active = array_values(array_filter($t['items'], fn ($i) => $i['status'] === 'active'));
        if (!$active) throw HttpException::bad('The sale has no items');
        $totals = self::recalc($id);
        $total = $totals['total'];

        $pays = [];
        foreach ($payments as $p) {
            $amt = r2($p['amount'] ?? 0);
            if ($amt <= 0) continue;
            $method = $p['method'] ?? '';
            if (!isset(CashSessions::PAYMENT_METHODS[$method])) throw HttpException::bad("Unknown payment method $method");
            if ($method !== 'cash' && $method !== 'charge' && Settings::get('require_payment_ref') === '1' && trim($p['reference'] ?? '') === '') {
                throw HttpException::bad('Reference / approval number is required for ' . CashSessions::PAYMENT_METHODS[$method]['label']);
            }
            $pays[] = ['method' => $method, 'amount' => $amt, 'reference' => trim($p['reference'] ?? '') ?: null, 'customer_id' => isset($p['customer_id']) ? (int) $p['customer_id'] : null];
        }
        if (!$pays) throw HttpException::bad('Enter the payment');
        $nonCash = r2(array_sum(array_map(fn ($p) => $p['method'] !== 'cash' ? $p['amount'] : 0, $pays)));
        $cash = r2(array_sum(array_map(fn ($p) => $p['method'] === 'cash' ? $p['amount'] : 0, $pays)));
        if ($nonCash > $total + 0.001) throw HttpException::bad('Non-cash payments cannot exceed the amount due');
        if (r2($nonCash + $cash) < $total) throw HttpException::bad('Payment is short by ₱' . number_format($total - $nonCash - $cash, 2));
        $change = r2($nonCash + $cash - $total);
        $charge = null;
        foreach ($pays as $p) if ($p['method'] === 'charge') $charge = $p;
        if ($charge && !$charge['customer_id']) throw HttpException::bad('Select the customer account to charge');
        // Stock check for every product on the sale (when the setting blocks selling below zero)
        $byItem = [];
        foreach ($active as $l) $byItem[$l['item_id']] = ($byItem[$l['item_id']] ?? 0) + $l['qty'] * $l['factor'];
        foreach ($byItem as $itemId => $q) self::checkStock(Inventory::item((int) $itemId), $q);

        DB::transaction(function () use ($t, $session, $active, $totals, $total, $pays, $nonCash, $cash, $change, $charge, $extra) {
            $receiptNo = Sequence::next('OR', Settings::get('receipt_prefix', 'SI') ?: 'SI', 8);
            $cashApplied = r2($cash - $change);
            $applied = [];   // GL account key => amount
            foreach ($pays as $p) {
                $amt = $p['amount'];
                if ($p['method'] === 'cash') { $amt = max($cashApplied, 0); $cashApplied = 0; }
                DB::insert('payments', [
                    'ticket_id' => $t['id'], 'cash_session_id' => $session['id'], 'method' => $p['method'], 'amount' => $amt,
                    'tendered' => $p['method'] === 'cash' ? $p['amount'] : $amt, 'change_amount' => $p['method'] === 'cash' ? $change : 0,
                    'reference' => $p['reference'], 'customer_id' => $p['customer_id'], 'user_id' => Auth::id(), 'created_at' => now(),
                ]);
                $key = CashSessions::PAYMENT_METHODS[$p['method']]['account'];
                $applied[$key] = r2(($applied[$key] ?? 0) + $amt);
            }

            // Inventory: remember the cost per base unit on each line, then deduct stock (lots: first expiry first)
            $lines = [];
            foreach ($active as $l) {
                DB::update('ticket_items', $l['id'], ['unit_cost' => Inventory::unitCost($l['item_id'])]);
                $lines[] = ['item_id' => $l['item_id'], 'qty' => r4($l['qty'] * $l['factor'])];
            }
            $ref = ['ref_type' => 'ticket', 'ref_id' => $t['id'], 'ref_no' => $receiptNo, 'bdate' => $t['business_date']];
            $cogs = Inventory::consume($lines, 'SALE', $ref);

            // General ledger
            $discountNet = Totals::discountNetOfVat($totals);
            $sales = r2($total - $totals['vat_amount'] + $discountNet);
            $glLines = [];
            foreach ($applied as $key => $amt) {
                $glLines[] = ['key' => $key, 'debit' => $amt, 'party_type' => $key === 'ar' ? 'customer' : null, 'party_id' => $key === 'ar' ? $charge['customer_id'] : null];
            }
            $glLines[] = ['key' => 'sales_discounts', 'debit' => $discountNet, 'memo' => $totals['discount_amount'] > 0 ? ($t['discount_name'] ?: 'Item discounts') : null];
            $glLines[] = ['key' => 'sales', 'credit' => $sales];
            $glLines[] = ['key' => 'output_vat', 'credit' => $totals['vat_amount']];
            $glLines[] = ['key' => 'cogs', 'debit' => $cogs];
            $glLines[] = ['key' => 'inventory', 'credit' => $cogs];
            $jeId = Ledger::post($t['business_date'], "POS sale $receiptNo", $glLines, 'pos_sale', $t['id'], $receiptNo);

            if ($charge) {
                $cust = DB::one('SELECT * FROM customers WHERE id = ?', [$charge['customer_id']]);
                if (!$cust) throw HttpException::notFound('Customer');
                DB::insert('ar_invoices', [
                    'invoice_no' => Sequence::next('AR', 'AR'), 'customer_id' => $cust['id'], 'inv_date' => $t['business_date'],
                    'due_date' => add_days($t['business_date'], (int) $cust['terms_days']), 'ref_no' => $receiptNo, 'description' => "POS charge $receiptNo",
                    'amount' => $applied['ar'], 'paid_amount' => 0, 'status' => 'open', 'income_account_id' => Ledger::account('sales'),
                    'source_type' => 'pos_sale', 'source_id' => $t['id'], 'journal_entry_id' => $jeId, 'created_by' => Auth::id(), 'created_at' => now(),
                ]);
            }
            $upd = [
                'status' => 'paid', 'receipt_no' => $receiptNo, 'paid_total' => r2($nonCash + $cash), 'change_amount' => $change, 'cogs' => $cogs,
                'paid_by' => Auth::id(), 'paid_at' => now(), 'journal_entry_id' => $jeId, 'customer_id' => $charge ? $charge['customer_id'] : $t['customer_id'],
            ];
            if (!empty($extra['rx_note'])) $upd['rx_note'] = mb_substr(trim((string) $extra['rx_note']), 0, 255);
            if (!empty($extra['customer_name'])) $upd['customer_name'] = mb_substr(trim((string) $extra['customer_name']), 0, 120);
            DB::update('tickets', $t['id'], $upd);
        });
        Audit::log('settle', 'ticket', $t['id'], ['total' => $total, 'change' => $change]);
        return self::get($t['id']);
    }

    /**
     * Cancel a held sale, or void a paid receipt (returns stock to the lots it came from, reverses the sale in the GL).
     * Voiding a receipt needs pos.void_receipt; receipts with returns must have the returns voided first.
     */
    public static function void(int $id, ?string $reason, ?string $pin = null): array
    {
        $t = self::get($id);
        if ($t['status'] === 'void') throw HttpException::bad('This sale is already void');
        if (!trim((string) $reason)) throw HttpException::bad('Void reason is required');

        if ($t['status'] === 'open') {
            DB::update('tickets', $id, ['status' => 'void', 'void_reason' => $reason, 'voided_by' => Auth::id(), 'voided_at' => now(), 'void_session_id' => $t['cash_session_id']]);
            Audit::log('cancel_sale', 'ticket', $id, ['reason' => $reason]);
            return self::get($id);
        }

        $by = Auth::authorize('pos.void_receipt', $pin);
        if (array_filter($t['returns'], fn ($r) => $r['status'] === 'posted')) throw HttpException::bad('This receipt has returns. Void the returns first.');
        $session = CashSessions::current();
        $cashPart = array_sum(array_map(fn ($p) => $p['method'] === 'cash' ? $p['amount'] : 0, $t['payments']));
        if ($cashPart > 0 && !$session) throw HttpException::bad('Open the business day first: the cash refund for this receipt comes out of the drawer.');
        DB::transaction(function () use ($t, $id, $reason, $by, $session) {
            $ar = DB::one("SELECT * FROM ar_invoices WHERE source_type = 'pos_sale' AND source_id = ? AND status <> 'void'", [$id]);
            if ($ar) {
                if ((float) $ar['paid_amount'] > 0) throw HttpException::bad("Receivable {$ar['invoice_no']} already has collections. Void the collections first.");
                DB::run("UPDATE ar_invoices SET status = 'void' WHERE id = ?", [$ar['id']]);
            }
            $sameDay = $session && (int) $session['id'] === $t['cash_session_id'];
            $vdate = $sameDay ? $t['business_date'] : ($session['business_date'] ?? today());
            Inventory::reverseMovements('ticket', $id, $vdate, "Void {$t['receipt_no']}");
            Ledger::reverse($t['journal_entry_id'] ? (int) $t['journal_entry_id'] : null, $vdate, "Void receipt {$t['receipt_no']}: $reason");
            DB::update('tickets', $id, ['status' => 'void', 'void_reason' => $reason, 'voided_by' => $by, 'voided_at' => now(), 'void_session_id' => $session['id'] ?? null]);
        });
        Audit::log('void_receipt', 'ticket', $id, ['receipt' => $t['receipt_no'], 'total' => $t['total'], 'reason' => $reason, 'authorized_by' => $by]);
        return self::get($id);
    }

    /** Receipts of the current day (or search by receipt / sale no / customer). */
    public static function receipts(?string $q = null): array
    {
        $where = ["t.status <> 'open'"];
        $params = [];
        if ($q) {
            $where[] = '(t.receipt_no ILIKE ? OR t.ticket_no ILIKE ? OR t.customer_name ILIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        } else {
            $s = CashSessions::current() ?? DB::one('SELECT * FROM cash_sessions ORDER BY id DESC LIMIT 1');
            if (!$s) return [];
            $where[] = 't.cash_session_id = ?';
            $params[] = $s['id'];
        }
        return array_map(fn ($r) => ['total' => (float) $r['total'], 'refunded_total' => (float) $r['refunded_total'], 'id' => (int) $r['id']] + $r, DB::all(
            'SELECT t.id, t.ticket_no, t.receipt_no, t.status, t.total, t.refunded_total, t.paid_at, t.created_at, t.customer_name,
                    t.business_date, t.void_reason, u.full_name AS cashier
             FROM tickets t LEFT JOIN users u ON u.id = COALESCE(t.paid_by, t.created_by)
             WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id DESC LIMIT 300', $params
        ));
    }
}
