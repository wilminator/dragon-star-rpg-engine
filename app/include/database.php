<?php
class Database {
    private PDO $connection;

    private function __construct(string $host, string $user, string $password, string $dbname) {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        // ATTR_ERRMODE=ERRMODE_EXCEPTION is PDO's equivalent of the old
        // MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT -- explicit rather
        // than relying on any driver default, self-documenting either way.
        $this->connection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    // Named, explicit entry point for MySQL -- matches sqlite()/
    // postgres()'s naming for full consistency across all three
    // drivers, now that there are three. The constructor stays
    // private: this is the only way to get a MySQL-backed instance.
    public static function mysql(string $host, string $user, string $password, string $dbname): self {
        return new self($host, $user, $password, $dbname);
    }

    // Separate entry point for tests: an SQLite-backed instance. Bypasses
    // the mysql-oriented constructor above entirely via reflection (same
    // pattern as DehydrateTrait::hydrate()'s newInstanceWithoutConstructor())
    // rather than trying to shoehorn "no host/user/password, just a path"
    // through a signature built for mysql's connection parameters.
    public static function sqlite(string $path = ':memory:'): self {
        $database = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $database->connection = new PDO("sqlite:$path", options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        return $database;
    }

    public static function postgres(string $host, string $user, string $password, string $dbname): self {
        $database = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $database->connection = new PDO("pgsql:host=$host;dbname=$dbname", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        return $database;
    }

    public function begin_transaction(): void {
        $this->connection->beginTransaction();
    }

    public function commit(): void {
        $this->connection->commit();
    }

    public function rollback(): void {
        $this->connection->rollBack();
    }

    public function prepare(string $query): PDOStatement {
        return $this->connection->prepare($query);
    }

    // Never fails, even when the table doesn't exist -- deliberately
    // avoids "try a query, catch the expected failure" as an existence
    // check. That pattern is fine on MySQL/SQLite, but breaks on
    // Postgres: any failed statement poisons the *entire* surrounding
    // transaction (verified directly), so a validate-query expected to
    // sometimes fail can't safely run inside one. Querying the driver's
    // own catalog for whether the table is already there sidesteps the
    // problem entirely, since it's just a normal SELECT that can return
    // zero rows -- never an error condition to catch in the first place.
    // $create_sql is expected to include "IF NOT EXISTS" -- the
    // database itself handles "already there, do nothing" atomically.
    // No separate existence check needed at all: no information_schema
    // query requiring its own read access beyond what CREATE TABLE
    // already needs, and no query that's ever expected to fail, so no
    // risk of Postgres's any-error-poisons-the-whole-transaction
    // behavior (confirmed directly -- this genuinely never poisons).
    public function ensure_table_exists(string $drop_sql, string $create_sql, bool $reset = false): void {
        if ($reset === true) {
            $this->connection->exec($drop_sql);
        } elseif (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+["`]?(\w+)/i', $create_sql, $name)
            && $this->has_table($name[1])) {
            // Already there: no CREATE at all. On MySQL any DDL commits
            // an open transaction on the spot, so a first save inside a
            // transaction (Alliance's purchases) used to commit early and
            // then fail at commit() -- "There is no active transaction".
            return;
        }
        $this->connection->exec($create_sql);
    }

    // Whether $table exists, asked without DDL.
    public function has_table(string $table): bool {
        $stmt = match ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => $this->connection->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"),
            'pgsql' => $this->connection->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?"),
            default => $this->connection->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"),
        };
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }


    public function get_by_id(string $table, string $key, int $id): ?array {
        $query = "SELECT * FROM {$table} WHERE {$key} = ?";
        $stmt = $this->connection->prepare($query);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // PDO's fetch() returns false on no match, unlike mysqli_result's
        // fetch_assoc() (which returned null) -- normalized back to null
        // here so this method's own ?array contract doesn't change.
        return $row === false ? null : $row;
    }

    public function get_all(string $table, bool $include_inactive = false): array {
        $query = "SELECT * FROM {$table}" . ($include_inactive ? "" : " WHERE state = 0");
        $stmt = $this->connection->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get_all_where(string $table, string $column, int $value): array {
        $query = "SELECT * FROM {$table} WHERE {$column} = ?";
        $stmt = $this->connection->prepare($query);
        $stmt->execute([$value]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get_all_where_in(string $table, string $column, array $values): array {
        if (empty($values)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $query = "SELECT * FROM {$table} WHERE {$column} IN ({$placeholders})";
        $stmt = $this->connection->prepare($query);
        $stmt->execute(array_values($values));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete_by_id(string $table, string $key, int $id): void {
        $query = "DELETE FROM {$table} WHERE {$key} = ?";
        $stmt = $this->connection->prepare($query);
        $stmt->execute([$id]);
    }

    // REPLACE INTO is a hard syntax error on Postgres (confirmed
    // directly) -- routes through the same portable upsert_query()
    // mechanism DbStoreTrait::save() already uses. $primary_key can be
    // a single column name or an array of them, for tables with a
    // composite key (e.g. team_relations' (teamid, otherteamid)).
    public function replace_row(string $table, array $data, string|array $primary_key): void {
        $columns = array_keys($data);
        $query = $this->upsert_query($table, $columns, $primary_key);
        $stmt = $this->connection->prepare($query);
        // PDO binds positionally from a plain value array -- no
        // mysqli-style "i"/"s" type-string needed.
        $stmt->execute(array_values($data));
    }

    public function delete_where(string $table, string $column, int $value): void {
        $query = "DELETE FROM {$table} WHERE {$column} = ?";
        $stmt = $this->connection->prepare($query);
        $stmt->execute([$value]);
    }

    public function mysql_do_insert_id(): int {
        return (int)$this->connection->lastInsertId();
    }

    // Most schema DDL doesn't need this -- INTEGER/TEXT/simple column
    // types and standard constraints work identically across drivers.
    // It exists for the rare case that genuinely can't be written in a
    // single dialect-neutral way (SQLite's AUTOINCREMENT keyword vs
    // MySQL's AUTO_INCREMENT, for instance).
    public function driver_name(): string {
        return $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    // The one genuinely dialect-specific fragment every entity's DDL
    // needs: there is no single native auto-increment/identity syntax
    // shared by MySQL, PostgreSQL, and SQLite. This is the only place
    // that variance should ever need to live -- everything else in a
    // CREATE TABLE (plain int/text/JSON/TIMESTAMP columns, unquoted
    // identifiers, standard constraints) is already portable as-is.
    public function auto_increment_primary_key(string $column_name): string {
        return match ($this->driver_name()) {
            'mysql' => "$column_name int NOT NULL AUTO_INCREMENT PRIMARY KEY",
            'pgsql' => "$column_name int GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY",
            'sqlite' => "$column_name INTEGER PRIMARY KEY AUTOINCREMENT",
            default => throw new \Exception("Unsupported driver: {$this->driver_name()}"),
        };
    }

    // MySQL and Postgres both have LEAST(a, b); SQLite has no LEAST()
    // at all, but its MIN() in this two-argument scalar form (distinct
    // from MIN()'s usual aggregate meaning) does the same thing.
    // $a/$b are SQL fragments (column names, placeholders, whatever the
    // caller already has), not values -- interpolated as-is into the
    // returned expression.
    public function least(string $a, string $b): string {
        return match ($this->driver_name()) {
            'mysql', 'pgsql' => "LEAST($a, $b)",
            'sqlite' => "MIN($a, $b)",
            default => throw new \Exception("Unsupported driver: {$this->driver_name()}"),
        };
    }

    // The one other genuinely dialect-specific fragment every entity's
    // save() needs: REPLACE INTO is a hard syntax error on Postgres
    // (confirmed directly), and there's no single upsert syntax shared
    // by all three drivers. Postgres and SQLite both support (and were
    // verified against directly) the same ON CONFLICT ... DO UPDATE
    // form; MySQL needs its own ON DUPLICATE KEY UPDATE instead.
    public function upsert_query(string $table, array $columns, string|array $primary_key): string {
        $key_columns = (array) $primary_key;
        $column_list = implode(',', $columns);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $non_key_columns = array_filter($columns, fn($c) => !in_array($c, $key_columns));
        $key_list = implode(',', $key_columns);

        // Every save column is part of the primary key (FIGHT, as of
        // its id-only $save_columns) -- nothing left to UPDATE on a
        // conflict, so the conventional SET clause below would be
        // empty, invalid syntax on every driver. Confirmed directly:
        // MySQL still requires at least one assignment in ON DUPLICATE
        // KEY UPDATE, so a harmless self-assignment covers it; Postgres
        // and SQLite both have DO NOTHING, dedicated syntax for exactly
        // this "insert if absent, otherwise no-op" case.
        if (count($non_key_columns) === 0) {
            if ($this->driver_name() === 'mysql') {
                $key_column = $key_columns[0];
                return "INSERT INTO $table ($column_list) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $key_column = VALUES($key_column)";
            }
            return "INSERT INTO $table ($column_list) VALUES ($placeholders) ON CONFLICT ($key_list) DO NOTHING";
        }

        if ($this->driver_name() === 'mysql') {
            $updates = implode(',', array_map(fn($c) => "$c = VALUES($c)", $non_key_columns));
            return "INSERT INTO $table ($column_list) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates";
        }

        $updates = implode(',', array_map(fn($c) => "$c = EXCLUDED.$c", $non_key_columns));
        return "INSERT INTO $table ($column_list) VALUES ($placeholders) ON CONFLICT ($key_list) DO UPDATE SET $updates";
    }

    // For a genuinely new row: the primary key column is left out of
    // the statement entirely, not passed as null. Postgres's identity
    // columns (unlike MySQL's AUTO_INCREMENT or SQLite's AUTOINCREMENT)
    // only auto-assign when the column is omitted outright -- an
    // explicit null violates the column's own not-null constraint
    // instead of triggering the default (confirmed directly).
    //
    // $columns can be empty -- an entity whose only column is its own
    // primary key (FIGHT, as of its id+initial_state-only shape) has
    // nothing left to insert once that key is excluded. Confirmed
    // directly that the three drivers split on syntax for this
    // specific case: MySQL accepts "() VALUES ()", but that's a syntax
    // error on Postgres and SQLite alike, both of which need
    // "DEFAULT VALUES" instead -- which is itself a syntax error on
    // MySQL, so this can't be one shared fragment the way most of this
    // class's portability fixes are.
    public function insert_query(string $table, array $columns): string {
        if (count($columns) === 0) {
            return match ($this->driver_name()) {
                'mysql' => "INSERT INTO $table () VALUES ()",
                'pgsql', 'sqlite' => "INSERT INTO $table DEFAULT VALUES",
                default => throw new \Exception("Unsupported driver: {$this->driver_name()}"),
            };
        }
        $column_list = implode(',', $columns);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        return "INSERT INTO $table ($column_list) VALUES ($placeholders)";
    }
}

function get_database(): Database {
    static $db = null;
    if ($db === null) {
        $type = getenv('DB_TYPE');
        if ($type === false) {
            throw new \Exception("DB_TYPE environment variable is not set. Must be one of: mysql, postgres, sqlite.");
        }
        $db = match (strtolower($type)) {
            'mysql' => Database::mysql(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_DATABASE')),
            'postgres' => Database::postgres(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_DATABASE')),
            'sqlite' => Database::sqlite(getenv('DB_DATABASE')),
            default => throw new \Exception("Unrecognized DB_TYPE '$type'. Must be one of: mysql, postgres, sqlite."),
        };
    }
    return $db;
}
