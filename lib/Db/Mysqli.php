<?php

declare(strict_types=1);

final class Db_Mysqli
{
    public static $Host;
    public static $Port;
    public static $Socket;
    public static $Database;
    public static $User;
    public static $Password;
    public static $Connection_Charset;

    public static $enableProfiling = false;

    public $Record = array();

    private static $mysqli  = null;
    private $mysqli_result;

    private function connect()
    {
        if (self::$mysqli !== null) {
            return;
        }

        $driver = new mysqli_driver();
        $driver->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;

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

    public static function resetInstance()
    {
        if (self::$mysqli === null) {
            return;
        }

        self::$mysqli->close();
        self::$mysqli = null;
    }

    public function getConnection(): mysqli
    {
        $this->connect();

        return self::$mysqli;
    }

    public function escape($string): string
    {
        $this->connect();

        return self::$mysqli->real_escape_string((string) $string);
    }

    public function query_id()
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

    public function query(string $query)
    {
        $this->connect();

        if ($this->mysqli_result) {
            $this->free();
        }

        if (self::$enableProfiling) {
            if (! isset($GLOBALS['queries'])) {
                $GLOBALS['queries'] = array();
            }

            end($GLOBALS['queries']);
            $key = key($GLOBALS['queries']);
            ++$key;

            $GLOBALS['queries'][$key]['query'] = $query;

            $query_start = microtime(true);
        }

        try {
            $this->mysqli_result = self::$mysqli->query($query);
        } catch (mysqli_sql_exception $mysqliException) {
            $message = sprintf("%s\n\n%s",
                $mysqliException->getMessage(),
                $query
            );

            throw new Db_Exception($message, self::$mysqli->errno, $mysqliException);
        }

        // The "$driver->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;"
        // should ensure that a "mysqli_sql_exception" is always raised in case of failure
        // but the documentation is not clear about if it occurs on EVERY failure.
        // As of yet no test covers this because I never found a false returned without exception
        if ($this->mysqli_result === false) {
            throw new Db_Exception(sprintf("The following query returned false:\n\n%s", $query));
        }

        if (self::$enableProfiling) {
            $GLOBALS['queries'][$key]['time'] = (microtime(true) - $query_start);
        }

        return $this->mysqli_result;
    }

    public function next_record()
    {
        $this->connect();

        if ($this->mysqli_result === null) {
            throw new Db_Exception('No query active for next_record()');
        }

        $this->Record = $this->mysqli_result->fetch_assoc();

        $stat = is_array($this->Record);

        if (! $stat) {
            $this->free();
        }

        return $stat;
    }

    public function affected_rows(): int
    {
        $this->connect();

        return self::$mysqli->affected_rows;
    }

    public function num_rows(): int
    {
        $this->connect();

        return $this->mysqli_result->num_rows;
    }

    public function f($Name)
    {
        $this->connect();

        return $this->Record[$Name];
    }

    public function metadata(string $table)
    {
        $this->connect();

        $id = $this->query('SELECT * FROM ' . $table . ' WHERE FALSE');

        $result = array();
        foreach ($id->fetch_fields() as $field) {
            // For the flags see MYSQLI_*_FLAG constants
            $result[] = array(
                'table'     => $field->table,
                'name'      => $field->name,
                'type'      => $field->type,
                'length'    => $field->length,
                'flags'     => $field->flags,
            );
        }

        $this->free();

        return $result;
    }

    public function last_insert_id()
    {
        $this->connect();

        return self::$mysqli->insert_id;
    }
}
