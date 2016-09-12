<?php

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

    public $Errno;
    public $Error;

    private static $mysqli  = null;
    private $mysqli_result  = null;

    private $statement;

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
        self::$mysqli->real_query('SET SQL_BIG_SELECTS = 1');
    }

    public static function resetInstance()
    {
        if (self::$mysqli === null) {
            return;
        }

        self::$mysqli->close();
        self::$mysqli = null;
    }

    public function getConnection()
    {
        $this->connect();

        return self::$mysqli;
    }

    public function escape($string)
    {
        $this->connect();

        return self::$mysqli->real_escape_string($string);
    }

    public function query_id()
    {
        $this->connect();

        return $this->mysqli_result;
    }

    public function free()
    {
        $this->connect();

        if ($this->mysqli_result instanceof mysqli_result) {
            $this->mysqli_result->free();
        }

        $this->mysqli_result = null;

        return $this;
    }

    public function query($query)
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

        if (self::$enableProfiling) {
            $GLOBALS['queries'][$key]['time'] = (microtime(true) - $query_start);
        }

        $this->Errno = self::$mysqli->errno;
        $this->Error = self::$mysqli->error;

        return $this->mysqli_result;
    }

    public function prepare($query)
    {
        $this->connect();

        if (empty($query)) {
            return false;
        }

        $this->statement = uniqid('stmt_');

        return $this->query(sprintf('PREPARE %s FROM \'%s\'', $this->statement, $query));
    }

    public function execute(array $params)
    {
        $this->connect();

        if ($this->statement === null) {
            throw new Db_Exception('No query prepared for execute()');
        }

        $params = array_values($params);
        $using = array();
        foreach ($params as $index => $value) {
            $result = $this->query('SET @param_' . $index . ' = "' . $this->escape($value) . '"');
            $using[] = '@param_' . $index;
        }

        $this->mysqli_result = $this->query('EXECUTE ' . $this->statement . ' USING ' . implode(',', $using));

        $this->statement = null;

        return $this->mysqli_result;
    }

    public function next_record()
    {
        $this->connect();

        if ($this->mysqli_result === null) {
            throw new Db_Exception('No query active for next_record()');
        }

        $this->Record = $this->mysqli_result->fetch_assoc();

        $this->Errno = self::$mysqli->errno;
        $this->Error = self::$mysqli->error;

        $stat = is_array($this->Record);

        if (! $stat) {
            $this->free();
        }

        return $stat;
    }

    public function affected_rows()
    {
        $this->connect();

        return self::$mysqli->affected_rows;
    }

    public function num_rows()
    {
        $this->connect();

        return $this->mysqli_result->num_rows;
    }

    public function f($Name)
    {
        $this->connect();

        return $this->Record[$Name];
    }

    public function metadata($table)
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
