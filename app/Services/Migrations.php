<?php
namespace App\Services;

use App\Core\DB;

/**
 * Database upgrades for installations made with an older version.
 * database/schema.sql always contains the latest structure for NEW installs; every change made after the
 * first release is also added here as a numbered step so existing databases are upgraded automatically.
 * Each step must be safe to run on a database that already has the change (see addColumn()).
 *
 * The current level is stored in settings.db_version. public/index.php calls runIfNeeded() on every request
 * (one cheap settings lookup); you can also run `php database/migrate.php`.
 */
class Migrations
{
    /** @return array<int, callable> version => upgrade step */
    private static function steps(): array
    {
        return [
            // 1: first release of Mark6 — discount presets
            1 => function () {
                Discounts::seedDefaults();
            },
        ];
    }

    public static function latest(): int
    {
        return max(array_keys(self::steps()));
    }

    public static function current(): int
    {
        return (int) DB::value('SELECT "value" FROM settings WHERE "key" = \'db_version\'');
    }

    public static function runIfNeeded(): void
    {
        if (self::current() < self::latest()) self::run();
    }

    /** Apply every step newer than the stored version. Returns the versions applied. */
    public static function run(): array
    {
        $applied = [];
        foreach (self::steps() as $version => $step) {
            if ($version <= self::current()) continue;
            $step();
            Settings::set('db_version', $version);
            $applied[] = $version;
        }
        return $applied;
    }

    /** ALTER TABLE ... ADD COLUMN only when the column does not exist yet. */
    public static function addColumn(string $table, string $column, string $definition): void
    {
        if (!DB::columnExists($table, $column)) DB::pdo()->exec('ALTER TABLE ' . DB::quoteIdent($table) . ' ADD COLUMN ' . DB::quoteIdent($column) . " $definition");
    }
}
