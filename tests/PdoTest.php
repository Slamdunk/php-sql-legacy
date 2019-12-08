<?php

declare(strict_types=1);

namespace SlamTest\Db;

use Db_Exception;
use Db_Pdo;
use Db_PdoStatement;
use Db_Profiler;
use Db_ProfilerQuery;
use PHPUnit\Framework\TestCase;

final class PdoTest extends TestCase
{
    /**
     * @var Db_Pdo
     */
    private $pdo;

    /**
     * @var int
     */
    private $maxLifeTimeBackup;

    protected function setUp(): void
    {
        $this->maxLifeTimeBackup = Db_Pdo::$maxLifeTime;
        Db_Pdo::resetInstance();

        $this->pdo = new Db_Pdo('sqlite::memory:', '', '', [
            'connection_charset' => 'UTF-8',
        ]);

        $this->pdo->exec('CREATE TABLE user (id INTEGER PRIMARY KEY ASC, name)');
    }

    protected function tearDown(): void
    {
        Db_Pdo::$maxLifeTime = $this->maxLifeTimeBackup;
    }

    public function testDsnBuilding(): void
    {
        \define('DB_SQL_HOST',      '1.2.3.4');
        \define('DB_SQL_PORT',      '5555');
        \define('DB_SQL_DATABASE',  'my_database');
        \define('DB_SQL_SOCKET',    'my_socket');

        $dsn = Db_Pdo::buildDsn([
            'port'   => '9999',
            'option' => 'my_option',
        ]);

        self::assertIsString($dsn);
        self::assertContains(DB_SQL_HOST, $dsn);
        self::assertNotContains(DB_SQL_PORT, $dsn);
        self::assertContains('9999', $dsn);
        self::assertContains('my_database', $dsn);
        self::assertContains('my_socket', $dsn);
        self::assertNotContains('my_option', $dsn);
    }

    public function testBaseQueries(): void
    {
        self::assertIsArray($this->pdo->getDbParams());

        $stmt = $this->pdo->insert('user', [
            'name' => 'Bob',
        ]);

        self::assertInstanceOf(Db_PdoStatement::class, $stmt);
        self::assertSame(1, $stmt->rowCount());

        $stmt = $this->pdo->query('SELECT id, name FROM user');

        self::assertInstanceOf(Db_PdoStatement::class, $stmt);

        $users = $stmt->fetchAll();

        self::assertIsArray($users);
        self::assertCount(1, $users);

        $user = \current($users);

        self::assertSame('Bob', $user['name']);

        $stmt = $this->pdo->update('user', [
            'name' => 'Alice',
        ], [
            'id' => $user['id'],
        ]);

        self::assertInstanceOf(Db_PdoStatement::class, $stmt);
        self::assertSame(1, $stmt->rowCount());

        $updatedUser = $this->pdo->query('SELECT id, name FROM user')->fetch();

        self::assertSame($user['id'], $updatedUser['id']);
        self::assertSame('Alice', $updatedUser['name']);

        $stmt = $this->pdo->delete('user', [
            'id' => $updatedUser['id'],
        ]);

        self::assertInstanceOf(Db_PdoStatement::class, $stmt);
        self::assertSame(1, $stmt->rowCount());
    }

    public function testTransactions(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->insert('user', [
            'name' => 'Alice',
        ]);
        $this->pdo->commit();

        $users = $this->pdo->query('SELECT id, name FROM user')->fetchAll();
        self::assertCount(1, $users);

        $this->pdo->beginTransaction();
        $this->pdo->insert('user', [
            'name' => 'Bob',
        ]);
        $this->pdo->rollBack();

        $users = $this->pdo->query('SELECT id, name FROM user')->fetchAll();
        self::assertCount(1, $users);
    }

    public function testSingleton(): void
    {
        Db_Pdo::setInstance($this->pdo);

        self::assertSame($this->pdo, Db_Pdo::getInstance());
    }

    public function testExplicitSingleton(): void
    {
        $this->expectException(Db_Exception::class);

        Db_Pdo::getInstance();
    }

    public function testAutomaticRenewSingleton(): void
    {
        Db_Pdo::$maxLifeTime = 0;
        Db_Pdo::setInstance($this->pdo);

        self::assertNotSame($this->pdo, Db_Pdo::getInstance());
    }

    public function testProfiler(): void
    {
        $profiler = $this->pdo->getProfiler();

        self::assertFalse($profiler->getEnabled());
        $profiler->setEnabled(true);
        self::assertTrue($profiler->getEnabled());

        self::assertFalse($profiler->getLastQueryProfile());

        self::assertCount(0, $profiler->getQueryProfiles());
        self::assertSame((float) 0, $profiler->getTotalElapsedSecs());
        self::assertSame(0, $profiler->getTotalNumQueries());

        $this->pdo->beginTransaction();
        $this->pdo->insert('user', [
            'name' => 'Bob',
        ]);
        $this->pdo->rollBack();

        $stmt      = $this->pdo->prepare('SELECT 1');
        $lastQuery = $profiler->getLastQueryProfile();

        self::assertInstanceOf(Db_ProfilerQuery::class, $lastQuery);
        self::assertSame('SELECT 1', $lastQuery->getQuery());
        self::assertEmpty($lastQuery->getQueryParams());
        self::assertFalse($lastQuery->getElapsedSecs());

        $stmt->execute();
        $stmt->execute();

        self::assertGreaterThan(0, $lastQuery->getElapsedSecs());

        self::assertCount(5, $profiler->getQueryProfiles());
        self::assertGreaterThan(0, $profiler->getTotalElapsedSecs());
        self::assertSame(5, $profiler->getTotalNumQueries());

        self::assertCount(1, $profiler->getQueryProfiles(Db_Profiler::INSERT));
        self::assertGreaterThan(0, $profiler->getTotalElapsedSecs(Db_Profiler::INSERT));
        self::assertSame(1, $profiler->getTotalNumQueries(Db_Profiler::INSERT));

        $profiler->clear();

        self::assertCount(0, $profiler->getQueryProfiles());
    }

    public function testIncosistentQueryState(): void
    {
        $profiler = new Db_Profiler();
        $profiler->setEnabled(true);

        $this->expectException(Db_Exception::class);

        $profiler->queryEnd(999);
    }

    public function testQueryCanBeEndedOneTime(): void
    {
        $profiler = new Db_Profiler();
        $profiler->setEnabled(true);
        $id = $profiler->queryStart('SELECT 1');
        $profiler->queryEnd($id);

        $this->expectException(Db_Exception::class);

        $profiler->queryEnd($id);
    }

    public function testNonExistingQuery(): void
    {
        $profiler = new Db_Profiler();

        $this->expectException(Db_Exception::class);

        $profiler->getQueryProfile(999);
    }

    public function testQuoteIdentifier(): void
    {
        self::assertSame('`space ``table`', $this->pdo->quoteIdentifier('space `table'));
        self::assertSame('`my space ``database`.`space ``table`', $this->pdo->quoteIdentifier('my space `database.space `table'));
        self::assertSame('`my space ``database.space ``table`', $this->pdo->quoteSingleIdentifier('my space `database.space `table'));
    }
}
