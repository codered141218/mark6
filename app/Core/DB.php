<?php
namespace App\Core;

use PDO;

/**
 * Small static wrapper around PDO for PostgreSQL (Supabase, Railway Postgres, or a local server).
 *
 *   DB::all('SELECT * FROM items WHERE category_id = ?', [$id]);   // list of rows
 *   DB::one('SELECT * FROM items WHERE id = ?', [$id]);            // one row or null
 *   DB::value('SELECT COUNT(*) FROM items');                       // single value
 *   DB::insert('items', ['name' => 'Rice']);                        // returns new id (tables with a serial id)
 *   DB::insertRow('settings', [...]);                               // insert without an id column
 *   DB::update('items', $id, ['name' => 'Rice']);
 *   DB::transaction(function () { ... });                           // commit or roll back
 *
 * Prepared statements are emulated client-side so the app also works through connection poolers
 * (Supabase transaction pooler / pgbouncer), which do not keep server-side prepared statements.
 */
class DB
{
    private static ?PDO $pdo = null;
    private static int $txDepth = 0;
    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$pdo = null;
        self::$txDepth = 0;
    }

    public static function config(): array
    {
        return self::$config;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = self::$config;
            self::$pdo = new PDO(self::dsn($c['database']), $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            // Keep NOW()/CURRENT_DATE in the same timezone as PHP, and work inside the configured schema.
            self::$pdo->exec("SET TIME ZONE '" . (new \DateTime())->format('P') . "'");
            self::$pdo->exec('SET search_path TO ' . self::quoteIdent(self::schema()) . ', public');
        }
        return self::$pdo;
    }

    /** The schema the app's tables live in (default "public"; tests use their own schema). */
    public static function schema(): string
    {
        $s = trim((string) (self::$config['schema'] ?? ''));
        return preg_match('/^\w+$/', $s) ? $s : 'public';
    }

    private static function dsn(string $database): string
    {
        $c = self::$config;
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'] ?? 5432, $database);
        $ssl = $c['sslmode'] ?? '';
        if ($ssl !== '') $dsn .= ';sslmode=' . $ssl;
        return $dsn;
    }

    /** Connect to the maintenance database (used by the installer / tests on a local server to create a database). */
    public static function server(): PDO
    {
        return new PDO(self::dsn(self::$config['maintenance_db'] ?? 'postgres'), self::$config['username'], self::$config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public static function reconnect(): void
    {
        self::$pdo = null;
        self::$txDepth = 0;
    }

    public static function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            $type = is_int($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR);
            if (is_bool($v)) { $v = $v ? 1 : 0; $type = PDO::PARAM_INT; }
            if (is_float($v)) { $v = (string) $v; }
            $stmt->bindValue($i + 1, $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = [])
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function run(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** Insert and return the new id (the table must have a serial "id" column). */
    public static function insert(string $table, array $data): int
    {
        $stmt = self::query(self::insertSql($table, $data) . ' RETURNING id', array_values($data));
        return (int) $stmt->fetchColumn();
    }

    /** Insert into a table without an id column (settings, sequences, conversions). */
    public static function insertRow(string $table, array $data): void
    {
        self::query(self::insertSql($table, $data), array_values($data));
    }

    private static function insertSql(string $table, array $data): string
    {
        $cols = array_map([self::class, 'quoteIdent'], array_keys($data));
        return sprintf('INSERT INTO %s (%s) VALUES (%s)', self::quoteIdent($table), implode(',', $cols), implode(',', array_fill(0, count($cols), '?')));
    }

    public static function update(string $table, int $id, array $data): void
    {
        if (!$data) return;
        $set = implode(', ', array_map(fn ($c) => self::quoteIdent($c) . ' = ?', array_keys($data)));
        self::query('UPDATE ' . self::quoteIdent($table) . " SET $set WHERE id = ?", [...array_values($data), $id]);
    }

    /** Run $fn inside a transaction. Nested calls use savepoints. */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $sp = 'sp' . self::$txDepth;
        if (self::$txDepth === 0) $pdo->beginTransaction();
        else $pdo->exec("SAVEPOINT $sp");
        self::$txDepth++;
        try {
            $result = $fn();
            self::$txDepth--;
            if (self::$txDepth === 0) $pdo->commit();
            else $pdo->exec("RELEASE SAVEPOINT $sp");
            return $result;
        } catch (\Throwable $e) {
            self::$txDepth--;
            if (self::$txDepth === 0) { if ($pdo->inTransaction()) $pdo->rollBack(); }
            else $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
            throw $e;
        }
    }

    /** Build "?,?,?" for IN (...) clauses. */
    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(count($values), 1), '?'));
    }

    /** True when a table exists in the app's schema. */
    public static function tableExists(string $table): bool
    {
        return (bool) self::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [self::schema(), $table]);
    }

    public static function columnExists(string $table, string $column): bool
    {
        return (bool) self::value('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?', [self::schema(), $table, $column]);
    }

    /** Names of the app's tables in the schema (alphabetical). */
    public static function tables(): array
    {
        return array_column(self::all("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name", [self::schema()]), 'table_name');
    }
}
