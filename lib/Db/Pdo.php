<?php

declare(strict_types=1);

final class Db_Pdo extends PDO
{
    public static int $maxLifeTime              = -1;
    private static ?self $currentSharedInstance = null;
    private int $startTime;

    private array $dbParams        = [];
    private ?Db_Profiler $profiler = null;

    /**
     * @param array<int|string, int|string> $driver_options
     */
    public function __construct(string $dsn, string $username = '', string $password = '', array $driver_options = [])
    {
        $original_driver_options = $driver_options;

        $driver_options[PDO::ATTR_STATEMENT_CLASS] = [
            Db_PdoStatement::class,
            [
                'adapter'   => $this,
                'fetchMode' => PDO::FETCH_ASSOC,
            ],
        ];

        $this->dbParams = [
            'dsn'               => $dsn,
            'username'          => $username,
            'password'          => $password,
            'driver_options'    => $original_driver_options,
        ];

        $this->startTime = \time();

        parent::__construct($dsn, $username, $password, $driver_options);
    }

    public static function buildDsn(array $dsnCustom = []): string
    {
        $dsnDefault = [
            'host'          => DB_SQL_HOST,
            'port'          => DB_SQL_PORT,
            'dbname'        => DB_SQL_DATABASE,
            'unix_socket'   => DB_SQL_SOCKET,
        ];
        $dsnArray = \array_merge($dsnDefault, $dsnCustom);

        $dsn = 'mysql:';
        foreach (\array_keys($dsnDefault) as $key) {
            if (! empty($dsnArray[$key])) {
                $dsn .= \sprintf('%s=%s;', $key, $dsnArray[$key]);
            }
        }

        return $dsn;
    }

    public static function getInstance(): self
    {
        if (null === self::$currentSharedInstance) {
            throw new Db_Exception('Nessuna connessione globale attivata');
        }

        if (self::$maxLifeTime >= 0 && (\time() - self::$currentSharedInstance->startTime) >= self::$maxLifeTime) {
            $dbParams                    = self::$currentSharedInstance->dbParams;
            self::$currentSharedInstance = null;

            return self::setInstance(new self(
                $dbParams['dsn'],
                $dbParams['username'],
                $dbParams['password'],
                $dbParams['driver_options']
            ));
        }

        return self::$currentSharedInstance;
    }

    public static function setInstance(self $instance): self
    {
        self::$currentSharedInstance = $instance;

        return self::$currentSharedInstance;
    }

    public static function resetInstance(): void
    {
        self::$currentSharedInstance = null;
    }

    public function getDbParams(): array
    {
        return $this->dbParams;
    }

    public function getProfiler(): Db_Profiler
    {
        if (null === $this->profiler) {
            $this->profiler = new Db_Profiler();
        }

        return $this->profiler;
    }

    public function exec(string $statement): int|false
    {
        $q   = $this->getProfiler()->queryStart($statement);
        $int = parent::exec($statement);
        $this->getProfiler()->queryEnd($q);

        return $int;
    }

    /**
     * @param mixed ...$args
     */
    public function query(string $statement, ...$args): Db_PdoStatement
    {
        // Needed for profiler
        /** @var Db_PdoStatement $stmt */
        $stmt = $this->prepare($statement);
        $stmt->execute(...$args);

        return $stmt;
    }

    public function insert(string $tableName, array $data): Db_PdoStatement
    {
        return $this->query('
            INSERT INTO ' . $tableName . ' (' . \implode(', ', \array_keys($data)) . ')
            VALUES (' . \implode(', ', \array_fill(0, \count($data), '?')) . ')
        ', \array_values($data));
    }

    public function update(string $tableName, array $data, array $identifier): Db_PdoStatement
    {
        $set = [];
        foreach ($data as $columnName => $value) {
            $set[] = $columnName . ' = ?';
        }

        $params = \array_merge(\array_values($data), \array_values($identifier));

        $sql  = 'UPDATE ' . $tableName . ' SET ' . \implode(', ', $set)
                . ' WHERE ' . \implode(' = ? AND ', \array_keys($identifier))
                . ' = ?';

        return $this->query($sql, $params);
    }

    public function delete(string $tableName, array $identifier): Db_PdoStatement
    {
        $criteria = [];
        foreach (\array_keys($identifier) as $columnName) {
            $criteria[] = $columnName . ' = ?';
        }

        $query = 'DELETE FROM ' . $tableName . ' WHERE ' . \implode(' AND ', $criteria);

        return $this->query($query, \array_values($identifier));
    }

    public function beginTransaction(): bool
    {
        $q      = $this->getProfiler()->queryStart('begin', Db_Profiler::TRANSACTION);
        $result = parent::beginTransaction();
        $this->getProfiler()->queryEnd($q);

        return $result;
    }

    public function commit(): bool
    {
        $q      = $this->getProfiler()->queryStart('commit', Db_Profiler::TRANSACTION);
        $result = parent::commit();
        $this->getProfiler()->queryEnd($q);

        return $result;
    }

    public function rollBack(): bool
    {
        $q      = $this->getProfiler()->queryStart('rollback', Db_Profiler::TRANSACTION);
        $result = parent::rollBack();
        $this->getProfiler()->queryEnd($q);

        return $result;
    }

    public function quoteIdentifier(string $str): string
    {
        if (false !== \strpos($str, '.')) {
            $parts = \array_map([$this, 'quoteSingleIdentifier'], \explode('.', $str));

            return \implode('.', $parts);
        }

        return $this->quoteSingleIdentifier($str);
    }

    public function quoteSingleIdentifier(string $str): string
    {
        static $c = '`';

        return $c . \str_replace($c, $c . $c, $str) . $c;
    }
}
