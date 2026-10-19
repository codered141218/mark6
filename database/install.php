<?php
/**
 * Command-line installer.
 *   php database/install.php                      tables + default data + admin (no sample catalog)
 *   php database/install.php --sample=pharmacy    also load a sample catalog: pharmacy | grocery | hardware
 *   php database/install.php --create-db          local PostgreSQL only: create the database first
 * Reads the database settings from the DATABASE_URL / DB_* environment variables or config/config.php.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Services\Installer;

if (!$GLOBALS['__installed']) {
    fwrite(STDERR, "No database settings found. Set DATABASE_URL (Supabase connection string) or copy config/config.example.php to config/config.php.\n");
    exit(1);
}
$sample = 'none';
foreach ($argv as $a) {
    if (str_starts_with($a, '--sample=')) $sample = substr($a, 9);
    if ($a === '--sample') $sample = 'pharmacy';
}
if (!isset(Installer::SAMPLES[$sample])) {
    fwrite(STDERR, "Unknown sample '$sample'. Use one of: " . implode(', ', array_keys(Installer::SAMPLES)) . "\n");
    exit(1);
}
set_time_limit(0);
try {
    if (in_array('--create-db', $argv, true)) Installer::createDatabase(config('db.database'));
    Installer::install($sample);
    echo "Installed" . ($sample !== 'none' ? " with the $sample sample catalog" : '') . ". Log in with admin / admin123 (manager PIN 1234) and change them under My Account.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
