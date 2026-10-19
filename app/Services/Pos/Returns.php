<?php
namespace App\Services\Pos;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\Sequence;

/**
 * Customer returns against a paid receipt (one or more lines, whole or partial quantities).
 *
 * The refund for a line is exactly what the customer paid for it (its share of every discount, see Totals),
 * so returning everything refunds the receipt total. Postings:
 *   Dr Sales Returns & Allowances (net of VAT), Dr Output VAT / Cr Cash on Hand (or card / e-wallet clearing)
 *   restock:   Dr Merchandise Inventory / Cr Cost of Goods Sold   (goods go back into the lots they came from)
 *   damaged:   Dr Shrinkage, Damage & Expiry / Cr Cost of Goods Sold
 * Cash refunds come out of the open drawer and reduce the expected cash of the day.
 */
class Returns
{
    public const METHODS = ['cash' => ['label' => 'Cash from drawer', 'account' => 'cash_on_hand'], 'card' => ['label' => 'Card reversal', 'account' => 'card_clearing'],
        'gcash' => ['label' => 'GCash', 'account' => 'ewallet_clearing'], 'maya' => ['label' => 'Maya', 'account' => 'ewallet_clearing'],
        'bank_transfer' => ['label' => 'Bank transfer', 'account' => 'ewallet_clearing']];

    public static function get(int $id): array
    {
        $r = DB::one(
            'SELECT r.*, t.receipt_no, t.ticket_no, u.full_name AS created_by_name FROM sales_returns r JOIN tickets t ON t.id = r.ticket_id
             LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ?', [$id]
        );
        if (!$r) throw HttpException::notFound('Return');
        $r['lines'] = DB::all('SELECT * FROM sales_return_lines WHERE return_id = ? ORDER BY id', [$id]);
        return self::numeric($r);
    }

    private static function numeric(array $r): array
    {
        foreach (['refund_amount', 'vat_amount', 'vat_exempt', 'cogs'] as $k) $r[$k] = (float) $r[$k];
        $r['id'] = (int) $r['id'];
        $r['restock'] = (int) $r['restock'];
        foreach ($r['lines'] ?? [] as &$l) {
            foreach (['qty', 'factor', 'price', 'amount', 'vat_amount', 'cogs'] as $k) $l[$k] = (float) $l[$k];
            $l['id'] = (int) $l['id']; $l['ticket_item_id'] = (int) $l['ticket_item_id'];
        }
        return $r;
    }

    public static function listForTicket(int $ticketId): array
    {
        return array_map(fn ($r) => self::numeric($r + ['lines' => DB::all('SELECT * FROM sales_return_lines WHERE return_id = ? ORDER BY id', [$r['id']])]),
            DB::all('SELECT r.*, u.full_name AS created_by_name FROM sales_returns r LEFT JOIN users u ON u.id = r.created_by WHERE r.ticket_id = ? ORDER BY r.id', [$ticketId]));
    }

    /**
     * Record a return. $lines = [['id' => ticket_item_id, 'qty' => n], ...] in the unit sold.
     * Needs pos.refund (or a manager PIN). Returns the new return (with lines).
     */
    public static function create(int $ticketId, array $lines, string $method, ?string $reason, bool $restock = true, ?string $pin = null): array
    {
        $by = Auth::authorize('pos.refund', $pin);
        $t = Tickets::get($ticketId);
        if ($t['status'] !== 'paid') throw HttpException::bad('Only paid receipts can have returns');
        if (!isset(self::METHODS[$method])) throw HttpException::bad('Choose how the customer is refunded');
        $session = CashSessions::requireOpen();
        $reason = trim((string) $reason) ?: null;
        if (!$reason) throw HttpException::bad('Reason for the return is required');

        $wanted = [];
        foreach ($lines as $l) {
            $q = r4((float) ($l['qty'] ?? 0));
            if ($q > 0) $wanted[(int) ($l['id'] ?? 0)] = r4(($wanted[(int) ($l['id'] ?? 0)] ?? 0) + $q);
        }
        if (!$wanted) throw HttpException::bad('Select the items being returned');
        $rawLines = DB::all('SELECT * FROM ticket_items WHERE ticket_id = ?', [$ticketId]);
        $totals = Totals::compute(DB::one('SELECT * FROM tickets WHERE id = ?', [$ticketId]), $rawLines);
        $byId = array_column($rawLines, null, 'id');

        $id = DB::transaction(function () use ($t, $wanted, $byId, $totals, $method, $reason, $restock, $session, $by) {
            $refund = $vat = $exempt = $cogs = 0.0;
            $rows = [];
            foreach ($wanted as $lineId => $q) {
                $l = $byId[$lineId] ?? null;
                if (!$l || $l['status'] !== 'active') throw HttpException::bad('A selected line is not on this receipt');
                $available = r4((float) $l['qty'] - (float) $l['returned_qty']);
                if ($q > $available + 0.00005) throw HttpException::bad("Only " . qty($available) . " of {$l['name']} can still be returned");
                $share = $q / (float) $l['qty'];
                $amount = r2($totals['line_net'][$lineId] * $share);
                $lineVat = r2($totals['line_vat'][$lineId] * $share);
                $lineCogs = r2($q * (float) $l['factor'] * (float) $l['unit_cost']);
                $refund += $amount;
                $vat += $lineVat;
                if ($lineVat == 0) $exempt += $amount;
                $cogs += $lineCogs;
                $rows[] = ['ticket_item_id' => $lineId, 'item_id' => (int) $l['item_id'], 'name' => $l['name'], 'qty' => $q, 'factor' => (float) $l['factor'],
                    'price' => (float) $l['price'], 'amount' => $amount, 'vat_amount' => $lineVat, 'cogs' => $lineCogs];
                DB::run('UPDATE ticket_items SET returned_qty = returned_qty + ? WHERE id = ?', [$q, $lineId]);
            }
            $refund = r2($refund);
            $id = DB::insert('sales_returns', [
                'return_no' => Sequence::next('RET', 'RET'), 'ticket_id' => $t['id'], 'cash_session_id' => $session['id'], 'business_date' => $session['business_date'],
                'reason' => $reason, 'refund_method' => $method, 'refund_amount' => $refund, 'vat_amount' => r2($vat), 'vat_exempt' => r2($exempt), 'cogs' => r2($cogs),
                'restock' => $restock ? 1 : 0, 'status' => 'posted', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            foreach ($rows as $r) DB::insert('sales_return_lines', $r + ['return_id' => $id]);

            // Stock back into the lots the sale took from (or written off as damaged)
            $stockCogs = 0.0;
            if ($restock) {
                $ref = ['ref_type' => 'return', 'ref_id' => $id, 'ref_no' => DB::value('SELECT return_no FROM sales_returns WHERE id = ?', [$id]), 'bdate' => $session['business_date']];
                foreach ($rows as $r) {
                    $item = Inventory::item($r['item_id']);
                    if (!Inventory::isStocked($item)) continue;
                    $baseQty = r4($r['qty'] * $r['factor']);
                    $stockCogs += abs(Inventory::move($ref + ['item_id' => $r['item_id'], 'qty' => $baseQty, 'mtype' => 'RETURN', 'unit_cost' => $baseQty ? $r['cogs'] / $baseQty : 0,
                        'notes' => "Return {$t['receipt_no']}", 'from_ref' => ['ref_type' => 'ticket', 'ref_id' => $t['id']]]));
                }
            }
            $jeId = Ledger::post($session['business_date'], "Sales return {$t['receipt_no']}" . ($reason ? " - $reason" : ''), [
                ['key' => 'sales_returns', 'debit' => r2($refund - $vat)],
                ['key' => 'output_vat', 'debit' => r2($vat)],
                ['key' => self::METHODS[$method]['account'], 'credit' => $refund],
                ['key' => $restock ? 'inventory' : 'wastage', 'debit' => $restock ? r2($stockCogs) : r2($cogs)],
                ['key' => 'cogs', 'credit' => $restock ? r2($stockCogs) : r2($cogs)],
            ], 'pos_return', $id, $t['receipt_no']);
            DB::update('sales_returns', $id, ['journal_entry_id' => $jeId]);
            DB::run('UPDATE tickets SET refunded_total = refunded_total + ? WHERE id = ?', [$refund, $t['id']]);
            Audit::log('return', 'ticket', $t['id'], ['return_id' => $id, 'refund' => $refund, 'method' => $method, 'restock' => $restock, 'authorized_by' => $by]);
            return $id;
        });
        return self::get($id);
    }

    /** Undo a return: reverse the journal entry and the stock movements, make the quantities returnable again. */
    public static function void(int $id, ?string $reason, ?string $pin = null): array
    {
        $by = Auth::authorize('pos.refund', $pin);
        $r = self::get($id);
        if ($r['status'] !== 'posted') throw HttpException::bad('This return is already void');
        if (!trim((string) $reason)) throw HttpException::bad('Reason is required');
        $session = CashSessions::current();
        if ($r['refund_method'] === 'cash' && !$session) throw HttpException::bad('Open the business day first: the cash comes back into the drawer.');
        DB::transaction(function () use ($r, $session, $reason) {
            Inventory::reverseMovements('return', $r['id'], $session['business_date'] ?? today(), "Void return {$r['return_no']}");
            Ledger::reverse($r['journal_entry_id'] ? (int) $r['journal_entry_id'] : null, $session['business_date'] ?? today(), "Void return {$r['return_no']}: $reason");
            foreach ($r['lines'] as $l) DB::run('UPDATE ticket_items SET returned_qty = returned_qty - ? WHERE id = ?', [$l['qty'], $l['ticket_item_id']]);
            DB::run('UPDATE tickets SET refunded_total = refunded_total - ? WHERE id = ?', [$r['refund_amount'], $r['ticket_id']]);
            DB::update('sales_returns', $r['id'], ['status' => 'void', 'reason' => $r['reason'] . " | VOID: $reason"]);
        });
        Audit::log('void_return', 'ticket', (int) $r['ticket_id'], ['return_id' => $r['id'], 'reason' => $reason, 'authorized_by' => $by]);
        return self::get($id);
    }

    /** Posted returns of a business day: count, total refunded and the cash part (for X/Z readings). */
    public static function sessionTotals(int $sessionId): array
    {
        $r = DB::one(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(refund_amount),0) AS amount, COALESCE(SUM(CASE WHEN refund_method = 'cash' THEN refund_amount ELSE 0 END),0) AS cash
             FROM sales_returns WHERE cash_session_id = ? AND status = 'posted'", [$sessionId]
        );
        return ['cnt' => (int) $r['cnt'], 'amount' => r2($r['amount']), 'cash' => r2($r['cash'])];
    }
}
