<?php
namespace App\Services\Pos;

use App\Services\Settings;

/**
 * Philippine retail bill computation. Selling prices are VAT-inclusive.
 *
 * Every line is worked out on its own and the receipt totals are the sums, so a partial return
 * can refund exactly what a line cost the customer (see line_net / line_vat).
 *
 * Discounts can be on single items (ticket_items.discount_*) and/or on the whole receipt (tickets.discount_*):
 *  - Senior Citizen / PWD (RA 9994 / RA 10754): the qualified lines are VAT-exempt and get the SC rate (20%)
 *    on their net-of-VAT amount. Either tag the senior's own items, or apply it to the whole receipt
 *    (then every line qualifies; an SC/PWD receipt cannot be combined with other discounts).
 *  - Percent / fixed amount discounts (employee, promo, 5% senior on basic goods ...) reduce the line amount.
 *    A receipt-level promo is spread over what is left of the non-SC lines.
 *  - Items flagged vat_exempt (maintenance medicines) carry no VAT: their amount goes to VAT-exempt sales.
 */
class Totals
{
    public static function compute(array $ticket, array $lines): array
    {
        $tax = Settings::tax();
        $div = 1 + $tax['vatRate'];
        $active = array_values(array_filter($lines, fn ($l) => $l['status'] === 'active'));
        $orderType = $ticket['discount_type'] ?? 'none';
        $orderIsSc = in_array($orderType, ['sc', 'pwd'], true);

        $gross = 0.0;
        $itemPromo = 0.0;
        $scDisc = 0.0;
        $info = [];              // line id => [lt, exempt, sc, promo(item), left]
        $promoBase = 0.0;        // what receipt-level promos apply to
        foreach ($active as $l) {
            $id = (int) $l['id'];
            $lt = r2($l['line_total']);
            $gross += $lt;
            $kind = $orderIsSc ? 'sc' : ($l['discount_kind'] ?? null);
            $exempt = !empty($l['vat_exempt']);
            $row = ['lt' => $lt, 'exempt' => $exempt, 'sc' => false, 'promo' => 0.0, 'disc' => 0.0];
            if ($kind === 'sc' || $kind === 'pwd') {
                $net = $exempt ? $lt : r2($lt / $div);          // SC/PWD sales are VAT-exempt
                $d = r2($net * $tax['scRate']);
                $row['sc'] = true;
                $row['net'] = $net;
                $row['left'] = r2($net - $d);
                $row['disc'] = $d;
                $scDisc += $d;
            } else {
                if ($kind === 'percent') $row['promo'] = r2($lt * min((float) $l['discount_value'], 100) / 100);
                elseif ($kind === 'amount') $row['promo'] = r2(min((float) $l['discount_value'], $lt));
                $row['disc'] = $row['promo'];
                $row['left'] = r2($lt - $row['promo']);
                $itemPromo += $row['promo'];
                $promoBase += $row['left'];
            }
            $info[$id] = $row;
        }
        $gross = r2($gross);
        $promoBase = r2($promoBase);

        // ---- receipt-level promo, spread over the non-SC lines (last one takes the rounding difference)
        $orderPromo = 0.0;
        if ($orderType === 'percent') $orderPromo = r2($promoBase * min((float) $ticket['discount_rate'], 100) / 100);
        elseif ($orderType === 'amount') $orderPromo = r2(min((float) $ticket['discount_rate'], max($promoBase, 0)));
        $ids = array_keys(array_filter($info, fn ($r) => !$r['sc']));
        $leftToSpread = $orderPromo;
        foreach ($ids as $i => $id) {
            $share = $i === count($ids) - 1 ? $leftToSpread : ($promoBase > 0 ? r2($info[$id]['left'] / $promoBase * $orderPromo) : 0.0);
            $info[$id]['order_promo'] = $share;
            $info[$id]['left'] = r2($info[$id]['left'] - $share);
            $leftToSpread = r2($leftToSpread - $share);
        }

        // ---- VAT breakdown per line
        $vatable = $vat = $exemptSales = $total = 0.0;
        $lineDisc = $lineNet = $lineVat = [];
        foreach ($info as $id => $r) {
            $due = $r['left'];
            $v = 0.0;
            if ($r['sc'] || $r['exempt']) {
                $exemptSales += $r['sc'] ? $r['net'] : $due;
            } else {
                $net = r2($due / $div);
                $v = r2($due - $net);
                $vatable += $net;
                $vat += $v;
            }
            $total += $due;
            $lineDisc[$id] = $r['disc'];
            $lineNet[$id] = $due;
            $lineVat[$id] = $v;
        }

        return [
            'subtotal' => $gross,
            'discount_amount' => r2($scDisc + $itemPromo + $orderPromo),
            'sc_discount' => r2($scDisc),
            'promo_discount' => r2($itemPromo + $orderPromo),
            'vatable_sales' => r2($vatable),
            'vat_amount' => r2($vat),
            'vat_exempt_sales' => r2($exemptSales),
            'total' => r2($total),
            'line_discounts' => $lineDisc,
            'line_net' => $lineNet,
            'line_vat' => $lineVat,
            // Discounts as booked to "Sales Discounts" (net of VAT): SC is already net; promos on VAT-able lines include VAT.
            'discount_net' => r2($scDisc + array_sum(array_map(fn ($r) => ($r['promo'] + ($r['order_promo'] ?? 0)) / ($r['exempt'] ? 1 : $div), $info))),
        ];
    }

    /** Discounts net of VAT, as booked to "Sales Discounts". */
    public static function discountNetOfVat(array $totals): float
    {
        return r2($totals['discount_net'] ?? ($totals['sc_discount'] + $totals['promo_discount'] / (1 + Settings::tax()['vatRate'])));
    }
}
