<?php
namespace App\Services;

use App\Core\DB;

/** Business settings stored as key/value pairs in the `settings` table. */
class Settings
{
    private static ?array $cache = null;

    public const BUSINESS_TYPES = ['general' => 'General retail', 'pharmacy' => 'Pharmacy / drugstore', 'grocery' => 'Grocery / minimart', 'hardware' => 'Hardware / construction supply'];

    public const DEFAULTS = [
        'business_name' => 'My Store',
        'business_type' => 'general',
        'business_address' => 'Manila, Philippines',
        'business_tin' => '000-000-000-00000',
        'business_phone' => '',
        'receipt_title' => 'SALES INVOICE',
        'receipt_footer' => 'Thank you for shopping with us!',
        'receipt_prefix' => 'SI',
        'vat_registered' => '1',
        'vat_rate' => '12',
        'sc_discount_rate' => '20',
        'require_payment_ref' => '0',
        'block_negative_stock' => '0',
        'scale_barcode_prefix' => '',
        'expiry_warn_days' => '90',
        'auto_backup' => '1',
        'backup_retention' => '30',
    ];

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            foreach (DB::all('SELECT "key", "value" FROM settings') as $r) self::$cache[$r['key']] = $r['value'];
        }
        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        DB::run('INSERT INTO settings ("key", "value") VALUES (?, ?) ON CONFLICT ("key") DO UPDATE SET "value" = EXCLUDED."value"', [$key, $value === null ? null : (string) $value]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** VAT / discount configuration used by the POS and inventory costing. */
    public static function tax(): array
    {
        $vatRegistered = self::get('vat_registered', '1') === '1';
        return [
            'vatRegistered' => $vatRegistered,
            'vatRate' => $vatRegistered ? (float) self::get('vat_rate', 12) / 100 : 0.0,
            'scRate' => (float) self::get('sc_discount_rate', 20) / 100,
        ];
    }

    /** POS behaviour flags. */
    public static function pos(): array
    {
        return [
            'blockNegativeStock' => self::get('block_negative_stock', '0') === '1',
            'scalePrefix' => trim((string) self::get('scale_barcode_prefix', '')),
            'expiryWarnDays' => max((int) self::get('expiry_warn_days', 90), 0),
            'businessType' => self::get('business_type', 'general'),
        ];
    }
}
