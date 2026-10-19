<?php
/**
 * Loads configuration, the class autoloader, helpers and the database settings.
 * Included by public/index.php, the installer and the tests.
 *
 * Configuration comes from (later entries win):
 *   1. config/config.example.php (defaults)
 *   2. config/config.php         (written by the web installer on a normal server)
 *   3. environment variables      (Railway / Docker / Supabase: DATABASE_URL or DB_HOST, DB_PORT, DB_NAME, DB_USER,
 *                                  DB_PASSWORD, DB_SCHEMA, DB_SSLMODE, APP_TIMEZONE, APP_DEBUG, APP_BASE_PATH)
 */

define('BASE_PATH', dirname(__DIR__));

// PSR-4 style autoloader: App\Services\Ledger -> app/Services/Ledger.php
spl_autoload_register(function (string $class) {
    if (!str_starts_with($class, 'App\\')) return;
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

require BASE_PATH . '/app/helpers.php';

$configFile = BASE_PATH . '/config/config.php';
$config = require BASE_PATH . '/config/config.example.php';
$configured = false;
if (is_file($configFile)) {
    $config = array_replace_recursive($config, require $configFile);
    $configured = true;
}

// ---- environment variables (12-factor hosting: Railway, Docker, Supabase)
$env = fn (string $k) => ($v = getenv($k)) === false || $v === '' ? null : $v;
if ($url = $env('DATABASE_URL') ?? $env('SUPABASE_DB_URL') ?? $env('POSTGRES_URL')) {
    $p = parse_url($url);
    if ($p && isset($p['host'])) {
        parse_str($p['query'] ?? '', $q);
        $config['db'] = [
            'host' => $p['host'], 'port' => (int) ($p['port'] ?? 5432), 'database' => ltrim($p['path'] ?? '/postgres', '/'),
            'username' => urldecode($p['user'] ?? 'postgres'), 'password' => urldecode($p['pass'] ?? ''),
            'schema' => $config['db']['schema'] ?? 'public', 'sslmode' => $q['sslmode'] ?? ($config['db']['sslmode'] ?? 'require'),
        ];
        $configured = true;
    }
}
foreach (['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_NAME' => 'database', 'DB_USER' => 'username', 'DB_PASSWORD' => 'password', 'DB_SCHEMA' => 'schema', 'DB_SSLMODE' => 'sslmode'] as $k => $key) {
    if (($v = $env($k)) !== null) { $config['db'][$key] = $key === 'port' ? (int) $v : $v; $configured = $configured || in_array($key, ['host', 'database'], true); }
}
if (($v = $env('DB_PERSISTENT')) !== null) $config['db']['persistent'] = in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
if (($v = $env('APP_TIMEZONE')) !== null) $config['app']['timezone'] = $v;
if (($v = $env('APP_DEBUG')) !== null) $config['app']['debug'] = in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
if (($v = $env('APP_BASE_PATH')) !== null) $config['app']['base_path'] = $v;
if (($v = $env('APP_TRUST_PROXY')) !== null) $config['app']['trust_proxy'] = in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
// Optional override, e.g. MARK6_DB_NAME=mark6_demo php -S ... (handy for testing several copies)
if ($env('MARK6_DB_NAME')) $config['db']['database'] = $env('MARK6_DB_NAME');

$GLOBALS['__config'] = $config;
$GLOBALS['__installed'] = $configured;   // "database settings are known" (tables may still have to be created)

date_default_timezone_set(config('app.timezone', 'Asia/Manila'));
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
if (is_dir(BASE_PATH . '/storage/logs') && is_writable(BASE_PATH . '/storage/logs')) ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

App\Core\DB::configure(config('db'));
