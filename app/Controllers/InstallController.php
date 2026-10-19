<?php
namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Installer;

/**
 * First-run web installer. On Railway / Docker the database comes from environment variables (DATABASE_URL),
 * so the form only asks for the admin account and the sample catalog; on a local server it also asks for the
 * PostgreSQL details and writes config/config.php.
 */
class InstallController
{
    public function redirect(Request $req): \App\Core\Response
    {
        return redirect('/install');
    }

    public function form(Request $req): string
    {
        return view('install/form', [
            'db' => config('db'), 'envMode' => $this->envMode(), 'samples' => Installer::SAMPLES,
            'zip' => class_exists(\ZipArchive::class), 'pgsql' => extension_loaded('pdo_pgsql'),
        ], 'blank');
    }

    public function install(Request $req): \App\Core\Response
    {
        if (!extension_loaded('pdo_pgsql')) throw HttpException::bad('The PHP pdo_pgsql extension is not installed');
        $envMode = $this->envMode();
        $db = config('db');
        if (!$envMode) {
            $db = [
                'host' => trim($req->input('host', '127.0.0.1')),
                'port' => (int) $req->input('port', 5432),
                'database' => trim($req->input('database', 'postgres')),
                'username' => trim($req->input('username', 'postgres')),
                'password' => (string) $req->input('password', ''),
                'schema' => trim($req->input('schema', 'public')) ?: 'public',
                'sslmode' => in_array($req->input('sslmode'), ['disable', 'prefer', 'require', 'verify-full'], true) ? $req->input('sslmode') : 'prefer',
            ];
            if (!preg_match('/^\w+$/', $db['schema'])) throw HttpException::bad('Schema name may only contain letters, numbers and _');
        }
        $adminPassword = (string) $req->input('admin_password', '');
        if (strlen($adminPassword) < 6) throw HttpException::bad('Admin password must be at least 6 characters');
        if ($adminPassword !== $req->input('admin_password_confirm')) throw HttpException::bad('Admin passwords do not match');
        $pin = (string) $req->input('admin_pin', '1234');
        if (!preg_match('/^\d{4,8}$/', $pin)) throw HttpException::bad('Manager PIN must be 4-8 digits');
        $sample = (string) $req->input('sample', 'none');
        if (!isset(Installer::SAMPLES[$sample])) throw HttpException::bad('Choose a sample catalog');

        DB::configure($db);
        set_time_limit(0);          // a remote database (Supabase) needs a few minutes of round trips
        ignore_user_abort(true);    // keep going even if the browser gives up waiting
        try {
            if (!$envMode && $req->input('create_db') === '1') Installer::createDatabase($db['database']);
            Installer::install($sample, $adminPassword, $pin);
        } catch (\PDOException $e) {
            throw HttpException::bad('Could not connect to PostgreSQL: ' . $e->getMessage());
        }
        if (!$envMode) Installer::writeConfig($db);
        flash('success', 'Installation complete. Log in as admin with the password you chose.');
        return redirect('/login');
    }

    /** True when the database settings come from the environment (Railway, Docker) and must not be asked for. */
    private function envMode(): bool
    {
        foreach (['DATABASE_URL', 'SUPABASE_DB_URL', 'POSTGRES_URL', 'DB_HOST'] as $k) {
            if (($v = getenv($k)) !== false && $v !== '') return true;
        }
        return false;
    }
}
