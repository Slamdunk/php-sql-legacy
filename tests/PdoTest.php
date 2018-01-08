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
            'port' => '9999',
            'option' => 'my_option',
        ]);

        $this->assertInternalType('string', $dsn);
        $this->assertContains(DB_SQL_HOST, $dsn);
        $this->assertNotContains(DB_SQL_PORT, $dsn);
        $this->assertContains('9999', $dsn);
        $this->assertContains('my_database', $dsn);
        $this->assertContains('my_socket', $dsn);
        $this->assertNotContains('my_option', $dsn);
    }

    public function testBaseQueries()
    {
        $this->assertInternalType('array', $this->pdo->getDbParams());

        $stmt = $this->pdo->insert('user', [
            'name' => 'Bob',
        ]);

        $this->assertInstanceOf(Db_PdoStatement::class, $stmt);
        $this->assertSame(1, $stmt->rowCount());

        $stmt = $this->pdo->query('SELECT id, name FROM user');

        $this->assertInstanceOf(Db_PdoStatement::class, $stmt);

        $users = $stmt->fetchAll();

        $this->assertCount(1, $users);

        $user = \current($users);

        $this->assertSame('Bob', $user['name']);

        $stmt = $this->pdo->update('user', [
            'name' => 'Alice',
        ], [
            'id' => $user['id'],
        ]);

        $this->assertInstanceOf(Db_PdoStatement::class, $stmt);
        $this->assertSame(1, $stmt->rowCount());

        $updatedUser = $this->pdo->query('SELECT id, name FROM user')->fetch();

        $this->assertSame($user['id'], $updatedUser['id']);
        $this->assertSame('Alice', $updatedUser['name']);

        $stmt = $this->pdo->delete('user', [
            'id' => $updatedUser['id'],
        ]);

        $this->assertInstanceOf(Db_PdoStatement::class, $stmt);
        $this->assertSame(1, $stmt->rowCount());
    }

    public function testTransactions()
    {
        $this->pdo->beginTransaction();
        $this->pdo->insert('user', [
            'name' => 'Alice',
        ]);
        $this->pdo->commit();

        $users = $this->pdo->query('SELECT id, name FROM user')->fetchAll();
        $this->assertCount(1, $users);

        $this->pdo->beginTransaction();
        $this->pdo->insert('user', [
            'name' => 'Bob',
        ]);
        $this->pdo->rollBack();

        $users = $this->pdo->query('SELECT id, name FROM user')->fetchAll();
        $this->assertCount(1, $users);
    }

    public function testSingleton()
    {
        Db_Pdo::setInstance($this->pdo);

        $this->assertSame($this->pdo, Db_Pdo::getInstance());
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

        $this->assertNotSame($this->pdo, Db_Pdo::getInstance());
    }

    public function testProfiler()
    {
        $profiler = $this->pdo->getProfiler();

        $this->assertFalse($profiler->getEnabled());
        $profiler->setEnabled(true);
        $this->assertTrue($profiler->getEnabled());

        $this->assertFalse($profiler->getLastQueryProfile());

        $this->assertCount(0, $profiler->getQueryProfiles());
        $this->assertSame((float) 0, $profiler->getTotalElapsedSecs());
        $this->assertSame(0, $profiler->getTotalNumQueries());

        $this->pdo->beginTransaction();
        $this->pdo->insert('user', [
            'name' => 'Bob',
        ]);
        $this->pdo->rollBack();

        $stmt = $this->pdo->prepare('SELECT 1');
        $lastQuery = $profiler->getLastQueryProfile();

        $this->assertInstanceOf(Db_ProfilerQuery::class, $lastQuery);
        $this->assertSame('SELECT 1', $lastQuery->getQuery());
        $this->assertEmpty($lastQuery->getQueryParams());
        $this->assertFalse($lastQuery->getElapsedSecs());

        $stmt->execute();
        $stmt->execute();

        $this->assertGreaterThan(0, $lastQuery->getElapsedSecs());

        $this->assertCount(5, $profiler->getQueryProfiles());
        $this->assertGreaterThan(0, $profiler->getTotalElapsedSecs());
        $this->assertSame(5, $profiler->getTotalNumQueries());

        $this->assertCount(1, $profiler->getQueryProfiles(Db_Profiler::INSERT));
        $this->assertGreaterThan(0, $profiler->getTotalElapsedSecs(Db_Profiler::INSERT));
        $this->assertSame(1, $profiler->getTotalNumQueries(Db_Profiler::INSERT));

        $profiler->clear();

        $this->assertCount(0, $profiler->getQueryProfiles());
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
        $this->assertSame('`space ``table`', $this->pdo->quoteIdentifier('space `table'));
        $this->assertSame('`my space ``database`.`space ``table`', $this->pdo->quoteIdentifier('my space `database.space `table'));
        $this->assertSame('`my space ``database.space ``table`', $this->pdo->quoteSingleIdentifier('my space `database.space `table'));
    }
}
