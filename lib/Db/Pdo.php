<?php

final class Db_Pdo extends PDO
{
    public static $sqlBigSelects = true;

    public static $maxLifeTime = -1;

    private static $currentSharedInstance;

    private $startTime;

    private $dbParams = array();

    private $profiler;

    public function __construct($dsn, $username = '', $password = '', $driver_options = array())
    {
        $original_driver_options = $driver_options;

        $driver_options[PDO::ATTR_STATEMENT_CLASS] = array(
            Db_PdoStatement::class,
            array(
                'adapter'   => $this,
                'fetchMode' => PDO::FETCH_ASSOC,
            ),
        );

        $driver_options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

        if (! empty($driver_options['connection_charset'])) {
            $driver_options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES ' . $driver_options['connection_charset'];
            unset($driver_options['connection_charset']);
        }

        $this->dbParams = array(
            'dsn'               => $dsn,
            'username'          => $username,
            'password'          => $password,
            'driver_options'    => $original_driver_options,
        );

        $this->startTime = time();

        return parent::__construct($dsn, $username, $password, $driver_options);
    }

    public static function buildDsn(array $dsnCustom = array())
    {
        $dsnDefault = array(
            'host'          => DB_SQL_HOST,
            'port'          => DB_SQL_PORT,
            'dbname'        => DB_SQL_DATABASE,
            'unix_socket'   => DB_SQL_SOCKET,
        );
        $dsnArray = array_merge($dsnDefault, $dsnCustom);

        $dsn = 'mysql:';
        foreach (array_keys($dsnDefault) as $key) {
            if (! empty($dsnArray[$key])) {
                $dsn .= sprintf('%s=%s;', $key, $dsnArray[$key]);
            }
        }

        return $dsn;
    }

    public static function getInstance()
    {
        if (self::$currentSharedInstance === null) {
            throw new Db_Exception('Nessuna connessione globale attivata');
        }

        if (self::$maxLifeTime >= 0 and (time() - self::$currentSharedInstance->startTime) >= self::$maxLifeTime) {
            $dbParams = self::$currentSharedInstance->dbParams;
            self::$currentSharedInstance = null;

            self::setInstance(new self(
                $dbParams['dsn'],
                $dbParams['username'],
                $dbParams['password'],
                $dbParams['driver_options']
            ));
        }

        return self::$currentSharedInstance;
    }

    public static function setInstance(Db_Pdo $instance)
    {
        self::$currentSharedInstance = $instance;

        self::$sqlBigSelects and self::$currentSharedInstance->query('SET SQL_BIG_SELECTS = 1');

        return self::$currentSharedInstance;
    }

    public static function resetInstance()
    {
        self::$currentSharedInstance = null;
    }

    public function getDbParams()
    {
        return $this->dbParams;
    }

    public function getProfiler()
    {
        if ($this->profiler === null) {
            $this->profiler = new Db_Profiler();
        }

        return $this->profiler;
    }

    public function exec($statement)
    {
        $q = $this->getProfiler()->queryStart($statement);
        $int = parent::exec($statement);
        $this->getProfiler()->queryEnd($q);

        return $int;
    }

    public function query($statement, array $binds = array())
    {
        // Needed for profiler
        $stmt = $this->prepare($statement);
        $stmt->execute($binds);

        return $stmt;
    }

    public function insert($tableName, array $data)
    {
        return $this->query('
            INSERT INTO ' . $tableName . ' (' . implode(', ', array_keys($data)) . ')
            VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')
        ', array_values($data));
    }

    public function update($tableName, array $data, array $identifier)
    {
        $set = array();
        foreach ($data as $columnName => $value) {
            $set[] = $columnName . ' = ?';
        }

        $params = array_merge(array_values($data), array_values($identifier));

        $sql  = 'UPDATE ' . $tableName . ' SET ' . implode(', ', $set)
                . ' WHERE ' . implode(' = ? AND ', array_keys($identifier))
                . ' = ?';

        return $this->query($sql, $params);
    }

    public function delete($tableName, array $identifier)
    {
        $criteria = array();
        foreach (array_keys($identifier) as $columnName) {
            $criteria[] = $columnName . ' = ?';
        }

        $query = 'DELETE FROM ' . $tableName . ' WHERE ' . implode(' AND ', $criteria);

        return $this->query($query, array_values($identifier));
    }

    public function beginTransaction()
    {
        $q = $this->getProfiler()->queryStart('begin', Db_Profiler::TRANSACTION);
        parent::beginTransaction();
        $this->getProfiler()->queryEnd($q);

        return $this;
    }

    public function commit()
    {
        $q = $this->getProfiler()->queryStart('commit', Db_Profiler::TRANSACTION);
        parent::commit();
        $this->getProfiler()->queryEnd($q);

        return $this;
    }

    public function rollBack()
    {
        $q = $this->getProfiler()->queryStart('rollback', Db_Profiler::TRANSACTION);
        parent::rollBack();
        $this->getProfiler()->queryEnd($q);

        return $this;
    }
}
