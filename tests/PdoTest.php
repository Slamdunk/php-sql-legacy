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
    private $pdo;

    private $maxLifeTimeBackup;

    protected function setUp()
    {
        $this->maxLifeTimeBackup = Db_Pdo::$maxLifeTime;
        Db_Pdo::resetInstance();

        $this->pdo = new Db_Pdo('sqlite::memory:', '', '', [
            'connection_charset' => 'UTF-8',
        ]);

        $this->pdo->exec('CREATE TABLE user (id INTEGER PRIMARY KEY ASC, name)');
    }

    protected function tearDown()
    {
        Db_Pdo::$maxLifeTime = $this->maxLifeTimeBackup;
    }

    public function testDsnBuilding()
    {
        \define('DB_SQL_HOST',      '1.2.3.4');
        \define('DB_SQL_PORT',      '5555');
        \define('DB_SQL_DATABASE',  'my_database');
        \define('DB_SQL_SOCKET',    'my_socket');

        $dsn = Db_Pdo::buildDsn([
            'port'   => '9999',
            'option' => 'my_option',
        ]);

        static::assertIsString($dsn);
        static::assertContains(DB_SQL_HOST, $dsn);
        static::assertNotContains(DB_SQL_PORT, $dsn);
        static::assertContains('9999', $dsn);
        static::assertContains('my_database', $dsn);
        static::assertContains('my_socket', $dsn);
        static::assertNotContains('my_option', $dsn);
    }

    public function testBaseQueries()
    {
        static::assertIsArray($this->pdo->getDbParams());

        $stmt = $this->pdo->insert('user', [
            'name' => 'Bob',
        ]);

        static::assertInstanceOf(Db_PdoStatement::class, $stmt);
        static::assertSame(1, $stmt->rowCount());

        $stmt = $this->pdo->query('SELECT id, name FROM user');

        static::assertInstanceOf(Db_PdoStatement::class, $stmt);

        $users = $stmt->fetchAll();

        static::assertIsArray($users);
        static::assertCount(1, $users);

        $user = \current($users);

        static::assertSame('Bob', $user['name']);

        $stmt = $this->pdo->update('user', [
            'name' => 'Alice',
        ], [
            'id' => $user['id'],
        ]);

        static::assertInstanceOf(Db_PdoStatement::class, $stmt);
        static::assertSame(1, $stmt->rowCount());

        $updatedUser = $this->pdo->query('SELECT id, name FROM user')->fetch();

        static::assertSame($user['id'], $updatedUser['id']);
        static::assertSame('Alice', $updatedUser['name']);

        $stmt = $this->pdo->delete('user', [
            'id' => $updatedUser['id'],
        ]);

        static::assertInstanceOf(Db_PdoStatement::class, $stmt);
        static::assertSame(1, $stmt->rowCount());
    }

    public function testTransactions()
    {
        $this->pdo->beginTransaction();
        $this->pdo->insert('user', [
            'name' => 'Alice',
        ]);
        $this->pdo->commit();

        $users = $this->pdo->query('SELECT id, name FROM user')->fetchAll();
        static::assertCount(1, $users);

        $this->pdo->beginTransaction();
        $this->pdo->insert('user', [
            'name' => 'Bob',
        ]);
        $this->pdo->rollBack();

        $users = $this->pdo->query('SELECT id, name FROM user')->fetchAll();
        static::assertCount(1, $users);
    }

    public function testSingleton()
    {
        Db_Pdo::setInstance($this->pdo);

        static::assertSame($this->pdo, Db_Pdo::getInstance());
    }

    public function testExplicitSingleton()
    {
        $this->expectException(Db_Exception::class);

        Db_Pdo::getInstance();
    }

    public function testAutomaticRenewSingleton()
    {
        Db_Pdo::$maxLifeTime = 0;
        Db_Pdo::setInstance($this->pdo);

        static::assertNotSame($this->pdo, Db_Pdo::getInstance());
    }

    public function testProfiler()
    {
        $profiler = $this->pdo->getProfiler();

        static::assertFalse($profiler->getEnabled());
        $profiler->setEnabled(true);
        static::assertTrue($profiler->getEnabled());

        static::assertFalse($profiler->getLastQueryProfile());

        static::assertCount(0, $profiler->getQueryProfiles());
        static::assertSame((float) 0, $profiler->getTotalElapsedSecs());
        static::assertSame(0, $profiler->getTotalNumQueries());

        $this->pdo->beginTransaction();
        $this->pdo->insert('user', [
            'name' => 'Bob',
        ]);
        $this->pdo->rollBack();

        $stmt      = $this->pdo->prepare('SELECT 1');
        $lastQuery = $profiler->getLastQueryProfile();

        static::assertInstanceOf(Db_ProfilerQuery::class, $lastQuery);
        static::assertSame('SELECT 1', $lastQuery->getQuery());
        static::assertEmpty($lastQuery->getQueryParams());
        static::assertFalse($lastQuery->getElapsedSecs());

        $stmt->execute();
        $stmt->execute();

        static::assertGreaterThan(0, $lastQuery->getElapsedSecs());

        static::assertCount(5, $profiler->getQueryProfiles());
        static::assertGreaterThan(0, $profiler->getTotalElapsedSecs());
        static::assertSame(5, $profiler->getTotalNumQueries());

        static::assertCount(1, $profiler->getQueryProfiles(Db_Profiler::INSERT));
        static::assertGreaterThan(0, $profiler->getTotalElapsedSecs(Db_Profiler::INSERT));
        static::assertSame(1, $profiler->getTotalNumQueries(Db_Profiler::INSERT));

        $profiler->clear();

        static::assertCount(0, $profiler->getQueryProfiles());
    }

    public function testIncosistentQueryState()
    {
        $profiler = new Db_Profiler();
        $profiler->setEnabled(true);

        $this->expectException(Db_Exception::class);

        $profiler->queryEnd(999);
    }

    public function testQueryCanBeEndedOneTime()
    {
        $profiler = new Db_Profiler();
        $profiler->setEnabled(true);
        $id = $profiler->queryStart('SELECT 1');
        $profiler->queryEnd($id);

        $this->expectException(Db_Exception::class);

        $profiler->queryEnd($id);
    }

    public function testNonExistingQuery()
    {
        $profiler = new Db_Profiler();

        $this->expectException(Db_Exception::class);

        $profiler->getQueryProfile(999);
    }

    public function testQuoteIdentifier()
    {
        static::assertSame('`space ``table`', $this->pdo->quoteIdentifier('space `table'));
        static::assertSame('`my space ``database`.`space ``table`', $this->pdo->quoteIdentifier('my space `database.space `table'));
        static::assertSame('`my space ``database.space ``table`', $this->pdo->quoteSingleIdentifier('my space `database.space `table'));
    }
}
