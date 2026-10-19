<?php
namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;
use PDO;

/**
 * Database backup & restore in plain PHP for PostgreSQL / Supabase (no pg_dump needed, works through a pooler).
 *
 * A backup is a .sql file in storage/backups named mark6_<kind>_<YYYYmmdd-His>.sql holding the DATA of every
 * table (the structure always comes from database/schema.sql):
 *   "-- Mark6 backup" header, BEGIN, SET CONSTRAINTS ALL DEFERRED, TRUNCATE of the tables in the file,
 *   multi-row INSERTs, setval() for every id sequence, COMMIT and an end marker.
 * Every statement ends with ";\n"; text is written as E'...' escape strings, so a file can be restored here or with psql.
 *
 *   Backup::create('manual');                 // returns ['name' => ..., 'kind' => ..., 'size' => ..., 'created_at' => ...]
 *   Backup::restore(Backup::path($name));     // replaces ALL data; a 'pre-restore' copy is made first
 *   Backup::autoBackup();                     // daily automatic backup (see app/routes/admin.php)
 *
 * Supabase also keeps its own daily backups (Pro plan); on Railway mount a volume on storage/ or download
 * the backups regularly, because the container disk does not survive a redeploy.
 */
class Backup
{
    public const HEADER = '-- Mark6 backup';
    public const FOOTER = '-- End of Mark6 backup';
    public const KINDS = ['manual' => ['Manual', 'green'], 'auto' => ['Automatic', 'blue'], 'pre-restore' => ['Before restore', 'amber']];

    /** Rows per INSERT statement, and a size cap so statements stay small. */
    private const BATCH_ROWS = 500;
    private const BATCH_BYTES = 512 * 1024;

    /** Folder holding the backup files; the tests point this at a temporary folder. */
    public static ?string $dir = null;

    public static function dir(): string
    {
        $dir = self::$dir ?? BASE_PATH . '/storage/backups';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the backup folder $dir");
        }
        return $dir;
    }

    /** Stored backups, newest first. */
    public static function list(): array
    {
        $list = [];
        foreach (glob(self::dir() . '/*.sql') as $file) {
            $name = basename($file);
            $kind = 'manual';
            $created = date('Y-m-d H:i:s', filemtime($file));
            $seq = 1; // several backups in the same second get -2, -3 ... (see create)
            if (preg_match('/^mark6_(.+)_(\d{8})-(\d{6})(?:-(\d+))?\.sql$/', $name, $m)) {
                $kind = $m[1];
                $created = date('Y-m-d H:i:s', strtotime("$m[2] $m[3]"));
                $seq = (int) ($m[4] ?? 1);
            }
            $list[] = ['name' => $name, 'kind' => $kind, 'size' => filesize($file), 'created_at' => $created, 'seq' => $seq];
        }
        usort($list, fn ($a, $b) => [$b['created_at'], $b['seq']] <=> [$a['created_at'], $a['seq']]);
        return $list;
    }

    /** Full path of a stored backup; rejects anything that is not a plain file name. */
    public static function path(string $name): string
    {
        if (!preg_match('/^\w[\w.-]*\.sql$/', $name)) throw HttpException::bad('Invalid backup name');
        $file = self::dir() . '/' . $name;
        if (!is_file($file)) throw HttpException::notFound('Backup');
        return $file;
    }

    /** Write a new backup of the whole database and return its list entry. */
    public static function create(string $kind = 'manual'): array
    {
        if (!isset(self::KINDS[$kind])) throw HttpException::bad('Unknown backup kind');
        set_time_limit(0);
        $base = self::dir() . '/mark6_' . $kind . '_' . date('Ymd-His');
        $file = $base . '.sql';
        for ($n = 2; is_file($file); $n++) $file = "$base-$n.sql";

        // Write to a temporary name first so a half-written file never shows up in the list.
        $part = $file . '.part';
        $fh = fopen($part, 'wb');
        if (!$fh) throw new \RuntimeException('Cannot write to the backup folder ' . self::dir());
        try {
            self::dump($fh);
            fclose($fh);
            rename($part, $file);
        } catch (\Throwable $e) {
            if (is_resource($fh)) fclose($fh);
            @unlink($part);
            throw $e;
        }
        if ($kind === 'auto') self::prune();
        $name = basename($file);
        foreach (self::list() as $b) if ($b['name'] === $name) return $b;
        throw new \RuntimeException('Backup was not saved');
    }

    public static function delete(string $name): void
    {
        unlink(self::path($name));
    }

    /**
     * Replace the whole database with the contents of a backup file.
     * A 'pre-restore' backup of the current data is taken first so the restore can be undone.
     * Returns ['safety_backup' => name of that copy].
     */
    public static function restore(string $file, string $source): array
    {
        self::validate($file);
        set_time_limit(0);
        ignore_user_abort(true); // closing the browser must not stop a restore half-way
        $safety = self::create('pre-restore');
        $pdo = DB::pdo();
        try {
            $pdo->exec('BEGIN');
            $pdo->exec('SET CONSTRAINTS ALL DEFERRED');
            // Empty every current table (tables the backup does not know keep nothing stale)
            $pdo->exec('TRUNCATE TABLE ' . implode(', ', array_map([DB::class, 'quoteIdent'], DB::tables())) . ' RESTART IDENTITY CASCADE');
            foreach (self::statements($file) as $sql) {
                $head = strtoupper(substr(ltrim($sql), 0, 8));
                if (str_starts_with($head, 'BEGIN') || str_starts_with($head, 'COMMIT') || str_starts_with($head, 'TRUNCATE') || str_starts_with($head, 'SET CONS')) continue;
                $pdo->exec($sql);
            }
            $pdo->exec('COMMIT');
        } catch (\PDOException $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $x) { /* connection may be gone */ }
            DB::reconnect();
            Settings::flush();
            throw HttpException::bad('Restore failed: ' . $e->getMessage()
                . ". Nothing was changed; your data was also saved as {$safety['name']}.");
        } finally {
            DB::reconnect();
            Settings::flush();
            Ledger::clearCache();
        }
        Migrations::runIfNeeded();   // a backup from an older version
        Audit::log('restore', 'database', null, ['from' => $source, 'safety_backup' => $safety['name']]);
        return ['safety_backup' => $safety['name']];
    }

    /** Restore from an uploaded file ($req->file('backup')). */
    public static function restoreUpload(?array $upload): array
    {
        if (!$upload) throw HttpException::bad('Choose a backup file (.sql) to upload. Files larger than ' . ini_get('upload_max_filesize') . ' cannot be uploaded.');
        if (!preg_match('/\.sql$/i', $upload['name'] ?? '')) throw HttpException::bad('Choose a .sql backup file');
        return self::restore($upload['tmp_name'], 'upload: ' . basename($upload['name']));
    }

    /** Refuse files that were not made by this module, or that were cut off while copying. */
    public static function validate(string $file): void
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) throw HttpException::bad('Cannot read the backup file');
        $first = rtrim((string) fgets($fh), "\r\n");
        fseek($fh, max(0, filesize($file) - 256));
        $tail = (string) stream_get_contents($fh);
        fclose($fh);
        if ($first !== self::HEADER) throw HttpException::bad('This file is not a Mark6 backup. Choose a .sql file made on the Backup & Restore page.');
        if (!str_contains($tail, self::FOOTER)) throw HttpException::bad('This backup file is incomplete (it may have been cut off while copying). Nothing was restored.');
    }

    /**
     * Daily automatic backup: runs when auto_backup = '1' and no automatic backup was made today.
     * The settings key last_auto_backup is claimed atomically, so two simultaneous requests never both run it.
     */
    public static function autoBackup(): ?array
    {
        if (Settings::get('auto_backup', '1') !== '1') return null;
        DB::run('INSERT INTO settings ("key", "value") VALUES (\'last_auto_backup\', \'\') ON CONFLICT ("key") DO NOTHING');
        $claimed = DB::run('UPDATE settings SET "value" = ? WHERE "key" = \'last_auto_backup\' AND ("value" IS NULL OR "value" <> ?)', [today(), today()]);
        Settings::flush();
        return $claimed ? self::create('auto') : null;
    }

    /** True when today's automatic backup is still to be made (cheap: uses the cached settings). */
    public static function autoBackupDue(): bool
    {
        return Settings::get('auto_backup', '1') === '1' && Settings::get('last_auto_backup') !== today();
    }

    /** Keep only the newest `backup_retention` automatic backups. Manual and pre-restore copies are never pruned. */
    private static function prune(): void
    {
        $keep = max(1, (int) Settings::get('backup_retention', 30));
        $autos = array_values(array_filter(self::list(), fn ($b) => $b['kind'] === 'auto'));
        foreach (array_slice($autos, $keep) as $b) @unlink(self::dir() . '/' . $b['name']);
    }

    /** Write the SQL for every table to an open file handle. */
    private static function dump($fh): void
    {
        $pdo = DB::pdo();
        $tables = DB::tables();
        $out = function (string $s) use ($fh) {
            if (fwrite($fh, $s) === false) throw new \RuntimeException('Writing the backup failed (disk full?)');
        };
        $q = fn ($t) => DB::quoteIdent($t);

        $out(self::HEADER . "\n-- Database: " . DB::value('SELECT current_database()') . ' | Schema: ' . DB::schema() . ' | Created: ' . now() . ' | Tables: ' . count($tables) . "\n\n"
            . "BEGIN;\nSET CONSTRAINTS ALL DEFERRED;\nTRUNCATE TABLE " . implode(', ', array_map($q, $tables)) . " RESTART IDENTITY CASCADE;\n");

        // One consistent snapshot of all tables, even while the POS keeps selling.
        $snapshot = !$pdo->inTransaction();
        if ($snapshot) { $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY'); }
        try {
            foreach ($tables as $table) {
                $stmt = $pdo->query('SELECT * FROM ' . $q($table) . ' ORDER BY 1');
                $head = null;
                $batch = [];
                $bytes = 0;
                $out("\n-- Table $table\n");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $head ??= 'INSERT INTO ' . $q($table) . ' (' . implode(', ', array_map($q, array_keys($row))) . ") VALUES\n";
                    $values = '(' . implode(', ', array_map([self::class, 'sqlValue'], $row)) . ')';
                    $batch[] = $values;
                    $bytes += strlen($values);
                    if (count($batch) >= self::BATCH_ROWS || $bytes >= self::BATCH_BYTES) {
                        $out($head . implode(",\n", $batch) . ";\n");
                        $batch = [];
                        $bytes = 0;
                    }
                }
                if ($batch) $out($head . implode(",\n", $batch) . ";\n");
                $stmt->closeCursor();
            }
            // Continue id numbering after the restored rows
            foreach ($tables as $table) {
                if (DB::columnExists($table, 'id') && DB::value("SELECT pg_get_serial_sequence(?, 'id')", [DB::quoteIdent(DB::schema()) . '.' . $q($table)])) {
                    $out("SELECT setval(pg_get_serial_sequence('" . DB::schema() . '.' . $table . "', 'id'), COALESCE((SELECT MAX(id) FROM " . $q($table) . "), 0) + 1, false);\n");
                }
            }
        } finally {
            if ($snapshot) $pdo->exec('COMMIT');
        }
        $out("COMMIT;\n" . self::FOOTER . "\n");
    }

    /** SQL literal for a value: NULL, TRUE/FALSE, bare integers, E'...' strings (backslash escapes). */
    private static function sqlValue($v): string
    {
        if ($v === null) return 'NULL';
        if (is_bool($v)) return $v ? 'TRUE' : 'FALSE';
        if (is_int($v)) return (string) $v;
        $s = (string) $v;
        return "E'" . str_replace(['\\', "'", "\n", "\r", "\0"], ['\\\\', "\\'", '\\n', '\\r', ''], $s) . "'";
    }

    /**
     * Read a .sql file statement by statement. A statement ends with ";" at the end of a line,
     * but only outside quoted strings, so text values containing ";\n" are kept intact.
     * Comment lines between statements are skipped.
     */
    public static function statements(string $file): \Generator
    {
        $fh = fopen($file, 'rb');
        $sql = '';
        $quote = null;
        try {
            while (($line = fgets($fh)) !== false) {
                if ($sql === '' && (trim($line) === '' || str_starts_with(ltrim($line), '--'))) continue;
                $quote = self::openQuote($line, $quote);
                $sql .= $line;
                if ($quote === null && preg_match('/;\s*$/', $line)) {
                    yield rtrim(rtrim($sql), ';');
                    $sql = '';
                }
            }
            if (trim($sql) !== '') yield trim($sql);
        } finally {
            fclose($fh);
        }
    }

    /** The quote character (' or ") still open at the end of $line, given the one open at its start. */
    private static function openQuote(string $line, ?string $quote): ?string
    {
        $i = 0;
        $n = strlen($line);
        while ($i < $n) {
            if ($quote === null) {
                $i += strcspn($line, "'\"", $i);
                if ($i >= $n) break;
                $quote = $line[$i++];
            } else {
                $i += strcspn($line, $quote . '\\', $i);
                if ($i >= $n) break;
                if ($line[$i] === '\\') { $i += 2; continue; } // backslash escape (E'' strings): skip the next character
                $quote = null; // closing quote ('' doubling simply closes and reopens)
                $i++;
            }
        }
        return $quote;
    }
}
