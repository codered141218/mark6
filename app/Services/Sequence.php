<?php
namespace App\Services;

use App\Core\DB;

/**
 * Document numbering.  Sequence::next('SI', 'SI', 8) -> "SI-00000001", then "SI-00000002" ...
 * Uses SELECT ... FOR UPDATE so two cashiers never get the same number.
 */
class Sequence
{
    public static function next(string $name, ?string $prefix = null, int $pad = 6): string
    {
        return DB::transaction(function () use ($name, $prefix, $pad) {
            $seq = DB::one('SELECT * FROM sequences WHERE name = ? FOR UPDATE', [$name]);
            if (!$seq) {
                DB::run('INSERT INTO sequences (name, prefix, next_no, pad) VALUES (?, ?, 1, ?) ON CONFLICT (name) DO NOTHING', [$name, $prefix ?: $name, $pad]);
                $seq = DB::one('SELECT * FROM sequences WHERE name = ? FOR UPDATE', [$name]);
            } elseif ($prefix && $prefix !== $seq['prefix']) {
                // e.g. the receipt prefix was changed in Settings
                DB::run('UPDATE sequences SET prefix = ? WHERE name = ?', [$prefix, $name]);
                $seq['prefix'] = $prefix;
            }
            DB::run('UPDATE sequences SET next_no = next_no + 1 WHERE name = ?', [$name]);
            return $seq['prefix'] . '-' . str_pad((string) $seq['next_no'], (int) $seq['pad'], '0', STR_PAD_LEFT);
        });
    }

    /** Make sure the next number of a series is at least $n (used after loading sample data). */
    public static function bump(string $name, string $prefix, int $pad, int $n): void
    {
        DB::run('INSERT INTO sequences (name, prefix, next_no, pad) VALUES (?, ?, ?, ?) ON CONFLICT (name) DO UPDATE SET next_no = GREATEST(sequences.next_no, EXCLUDED.next_no)', [$name, $prefix, $n, $pad]);
    }
}
