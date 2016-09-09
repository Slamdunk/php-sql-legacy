<?php

final class Db_Mysqli
{
    private $Host;
    private $Port;
    private $Socket;
    private $Database;
    private $User;
    private $Password;
    private $Connection_Charset;

    public $Halt_On_Error   = 'yes';

    public $Record          = array();

    public $Errno           = 0;
    public $Error           = '';

    private static $mysqli  = null;
    private $mysqli_result  = null;

    private $Prepared       = false;
    private $Statement      = '';

    public function __construct()
    {
        $this->Host                 = DB_SQL_HOST;
        $this->Port                 = DB_SQL_PORT;
        $this->Socket               = DB_SQL_SOCKET;
        $this->Database             = DB_SQL_DATABASE;
        $this->User                 = DB_SQL_USER;
        $this->Password             = DB_SQL_PASSWORD;
        $this->Connection_Charset   = DB_SQL_CONNECTION_CHARSET;

        $this->connect();

        $this->Halt_On_Error        = defined('DEBUG') ? 'yes' : 'no';
    }

    private function connect()
    {
        if (self::$mysqli === null) {
            $driver = new mysqli_driver();
            $driver->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;

            self::$mysqli = new mysqli(
                $this->Host,
                $this->User,
                $this->Password,
                $this->Database,
                $this->Port,
                $this->Socket
            );

            if (! self::$mysqli or self::$mysqli->connect_errno) {
                $this->halt('Connessione fallita ("' . $this->Host . '", "' . $this->User . '", "' . $this->Database . '"): ' . self::$mysqli->connect_error);

                return;
            }

            self::$mysqli->real_query('SET CHARACTER SET "' . $this->Connection_Charset . '"');

            self::$mysqli->real_query('SET SQL_BIG_SELECTS = 1');
        }

        return self::$mysqli;
    }

    public function getConnection()
    {
        return self::$mysqli;
    }

    public function escape($string)
    {
        return self::$mysqli->real_escape_string($string);
    }

    public function query_id()
    {
        return $this->mysqli_result;
    }

    public function free()
    {
        if ($this->mysqli_result instanceof mysqli_result) {
            $this->mysqli_result->free();
        }

        $this->mysqli_result = null;

        return $this;
    }

    public function query($Query_String)
    {
        if ($this->mysqli_result) {
            $this->free();
        }

        if (defined('DEBUG') and DEBUG and PHP_SAPI !== 'cli') {
            if (! isset($GLOBALS['queries'])) {
                $GLOBALS['queries'] = array();
            }

            end($GLOBALS['queries']);
            $key = key($GLOBALS['queries']);
            ++$key;

            $GLOBALS['queries'][$key]['query'] = $Query_String;

            $query_start = microtime(true);
        }

        $this->mysqli_result = self::$mysqli->query($Query_String);

        if (isset($key)) {
            $GLOBALS['queries'][$key]['time'] = (microtime(true) - $query_start);
        }

        $this->Errno = self::$mysqli->errno;
        $this->Error = self::$mysqli->error;

        if ($this->mysqli_result === false or $this->Errno) {
            $this->halt('Invalid SQL: ' . $Query_String);
        }

        return $this->mysqli_result;
    }

    public function prepare($Query)
    {
        if (empty($Query)) {
            return false;
        }

        $this->Statement = uniqid('stmt_');

        $prepare = 'PREPARE ' . $this->Statement . ' FROM \'' . $Query . '\'';

        $this->Prepared = $this->query($prepare);

        return $this;
    }

    public function execute($Param)
    {
        if (! $this->Prepared) {
            $this->halt('no prepared statement found');
        }

        if (empty($Param)) {
            $this->halt('empty params sent to prepared statement');
        }

        if (is_array($Param)) {
            foreach ($Param as $k => $v) {
                $result = $this->query('SET @param' . $k . ' = "' . $v . '"');
                if (! $result) {
                    return false;
                }
            }
        } else {
            $result = $this->query('SET @param = "' . $Param . '"');
            if (! $result) {
                return false;
            }
        }

        if (is_array($Param)) {
            $using = '';
            foreach ($Param as $k => $v) {
                $using .= '@param' . $k . ',';
            }
            $this->mysqli_result = $this->query('EXECUTE ' . $this->Statement . ' USING ' . rtrim($using, ','));
        } else {
            $this->mysqli_result = $this->query('EXECUTE ' . $this->Statement . ' USING @param');
        }

        return $this->mysqli_result;
    }

    public function next_record()
    {
        if (! $this->mysqli_result) {
            $this->halt('next_record called with no query pending.');

            return;
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
        return self::$mysqli->affected_rows;
    }

    public function num_rows()
    {
        return $this->mysqli_result->num_rows;
    }

    public function f($Name)
    {
        return $this->Record[$Name];
    }

    public function metadata($table)
    {
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

    public function halt($msg)
    {
        $this->Errno = self::$mysqli->errno;
        $this->Error = self::$mysqli->error;

        if ($this->Halt_On_Error === 'no') {
            return;
        }

        $this->haltmsg($msg);
    }

    public function haltmsg($msg)
    {
        $debugBacktrace = debug_backtrace();
        $trace = array();
        foreach ($debugBacktrace as $val) {
            $trace[] =
                  (isset($val['file']) ? ($val['file'] . ':') : '')
                . (isset($val['line']) ? ($val['line'] . '/') : '')
                . $val['function']
            ;
        }

        if (PHP_SAPI === 'cli') {
            echo
                  'Error: (' . $this->Errno . ') ' . $this->Error . PHP_EOL
                . 'Query:' . PHP_EOL
                . $msg . PHP_EOL
            ;

            echo sprintf('Backtrace: %s%s%s', PHP_EOL, implode(PHP_EOL, $trace), PHP_EOL);
        } else {
            echo
                  '<b>Error:</b> (' . $this->Errno . ') ' . $this->Error . '<br />'
                . '<b>Query:</b>'
                . '<pre>' . htmlspecialchars($msg, ENT_COMPAT, 'ISO-8859-1') . '</pre>'
            ;

            echo sprintf('<b>Backtrace</b>: <pre>%s</pre>', implode('<br />', $trace));
        }
    }

    public function last_insert_id()
    {
        return self::$mysqli->insert_id;
    }
}
