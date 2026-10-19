<?php
/**
 * Test runner (no PHPUnit needed):   php tests/run.php            run every tests/*Test.php
 *                                    php tests/run.php Pos        only files whose name contains "Pos"
 * Uses a separate PostgreSQL schema (MARK6_TEST_SCHEMA, default "mark6_test") in the configured database,
 * re-created for every test file so files never affect each other. Nothing in the live schema is touched.
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Auth;
use App\Core\DB;
use App\Services\Installer;

$db = config('db');
$db['schema'] = getenv('MARK6_TEST_SCHEMA') ?: 'mark6_test';
if ($db['schema'] === 'public') { fwrite(STDERR, "Refusing to run the tests in the public schema\n"); exit(2); }
DB::configure($db);

/** Every test file starts from a freshly installed schema (with the pharmacy sample catalog), logged in as admin. */
function fresh_database(array $db): void
{
    DB::reconnect();
    DB::pdo()->exec('DROP SCHEMA IF EXISTS ' . DB::quoteIdent($db['schema']) . ' CASCADE');
    App\Services\Ledger::clearCache();
    App\Services\Settings::flush();
    Installer::install('pharmacy');
    App\Services\Ledger::clearCache();
    App\Services\Settings::flush();
    Auth::actAs((int) DB::value("SELECT id FROM users WHERE username = 'admin'"));
}

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    if ($filter && !str_contains(basename($file), $filter)) continue;
    echo "\n" . basename($file) . "\n";
    fresh_database($db);
    require $file;
}
DB::reconnect();
DB::pdo()->exec('DROP SCHEMA IF EXISTS ' . DB::quoteIdent($db['schema']) . ' CASCADE');
exit(T::summary());
