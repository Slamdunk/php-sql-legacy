<?php

declare(strict_types=1);

final class Db_Mysqli
{
    public static string $Host;
    public static int $Port;
    public static string $Socket;
    public static string $Database;
    public static string $User;
    public static string $Password;
    public static string $Connection_Charset;
    public static bool $enableProfiling = false;

    public ?array $Record          = null;
    private static ?mysqli $mysqli = null;

    private null|bool|mysqli_result $mysqli_result = null;

    private function connect(): void
    {
        if (null !== self::$mysqli) {
            return;
        }

        $driver              = new mysqli_driver();
        $driver->report_mode = \MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT;

        self::$mysqli = new mysqli(
            self::$Host,
            self::$User,
            self::$Password,
            self::$Database,
            self::$Port,
            self::$Socket
        );

        self::$mysqli->real_query('SET CHARACTER SET "' . self::$Connection_Charset . '"');
    }

    public static function resetInstance(): void
    {
        if (null === self::$mysqli) {
            return;
        }

        self::$mysqli->close();
        self::$mysqli = null;
    }

    public function getConnection(): mysqli
    {
        $this->connect();
        \assert(self::$mysqli instanceof mysqli);

        return self::$mysqli;
    }

    public function escape(mixed $string): string
    {
        $this->connect();
        \assert(self::$mysqli instanceof mysqli);

        return self::$mysqli->real_escape_string((string) $string);
    }

    public function query_id(): null|bool|mysqli_result
    {
        $this->connect();

        return $this->mysqli_result;
    }

    public function free(): self
    {
        $this->connect();

        if ($this->mysqli_result instanceof mysqli_result) {
            $this->mysqli_result->free();
        }

        $this->mysqli_result = null;

        return $this;
    }

    public function query(string $query): null|bool|mysqli_result
    {
        $this->connect();
        \assert(self::$mysqli instanceof mysqli);

        if ($this->mysqli_result) {
            $this->free();
        }

        $key = $query_start = null;
        if (self::$enableProfiling) {
            if (! isset($GLOBALS['queries'])) {
                $GLOBALS['queries'] = [];
            }

            \end($GLOBALS['queries']);
            $key = \key($GLOBALS['queries']);
            ++$key;

            $GLOBALS['queries'][$key]['query'] = $query;

            $query_start = \microtime(true);
        }

        try {
            $this->mysqli_result = self::$mysqli->query($query);
        } catch (mysqli_sql_exception $mysqliException) {
            $message = \sprintf(
                "%s\n\n%s",
                $mysqliException->getMessage(),
                $query
            );

            throw new Db_Exception($message, self::$mysqli->errno, $mysqliException);
        }

        // The "$driver->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;"
        // should ensure that a "mysqli_sql_exception" is always raised in case of failure
        // but the documentation is not clear about if it occurs on EVERY failure.
        // As of yet no test covers this because I never found a false returned without exception
        if (false === $this->mysqli_result) {
            throw new Db_Exception(\sprintf("The following query returned false:\n\n%s", $query));
        }

        if (self::$enableProfiling) {
            $GLOBALS['queries'][$key]['time'] = (\microtime(true) - $query_start);
        }

        return $this->mysqli_result;
    }

    public function next_record(): bool
    {
        $this->connect();

        if (! $this->mysqli_result instanceof mysqli_result) {
            throw new Db_Exception('No query active for next_record()');
        }

        $this->Record = $this->mysqli_result->fetch_assoc();

        $stat = \is_array($this->Record);

        if (! $stat) {
            $this->free();
        }

        return $stat;
    }

    public function affected_rows(): int|string
    {
        $this->connect();
        \assert(self::$mysqli instanceof mysqli);

        return self::$mysqli->affected_rows;
    }

    public function num_rows(): int|string
    {
        $this->connect();
        \assert($this->mysqli_result instanceof mysqli_result);

        return $this->mysqli_result->num_rows;
    }

    public function f(mixed $Name): mixed
    {
        $this->connect();
        \assert(\is_array($this->Record));

        return $this->Record[$Name];
    }

    public function metadata(string $table): array
    {
        $this->connect();

        $id = $this->query('SELECT * FROM ' . $table . ' WHERE FALSE');
        \assert($id instanceof mysqli_result);
        $fields = $id->fetch_fields();
        \assert(\is_iterable($fields));

        $result = [];
        foreach ($fields as $field) {
            // For the flags see MYSQLI_*_FLAG constants
            $result[] = [
                'table'     => $field->table,
                'name'      => $field->name,
                'type'      => $field->type,
                'length'    => $field->length,
                'flags'     => $field->flags,
            ];
        }

        $this->free();

        return $result;
    }

    public function last_insert_id(): int|string
    {
        $this->connect();
        \assert(self::$mysqli instanceof mysqli);

        return self::$mysqli->insert_id;
    }
}
