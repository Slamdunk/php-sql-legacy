<?php

declare(strict_types=1);

namespace SlamTest\Db;

use Db_Exception;
use Db_Mysqli;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;

final class MysqliTest extends TestCase
{
    private $db;

    protected function setUp()
    {
        $parameters = [
            'Host'                   => '',
            'Port'                   => 0,
            'Socket'                 => '/var/run/mysqld/mysqld-5.6.sock',
            'Database'               => 'tools_ci_test',
            'User'                   => 'tools_ci',
            'Password'               => 'tools_ci',
            'Connection_Charset'     => 'latin1',
        ];

        if (false !== \getenv('TRAVIS')) {
            $parameters = [
                'Host'                   => '127.0.0.1',
                'Port'                   => 3306,
                'Socket'                 => '',
                'Database'               => 'tools_ci_test',
                'User'                   => 'root',
                'Password'               => '',
                'Connection_Charset'     => 'latin1',
            ];
        }

        Db_Mysqli::resetInstance();
        foreach ($parameters as $key => $value) {
            Db_Mysqli::${$key} = $value;
        }

        $this->db = new Db_Mysqli();
    }

    public function testRaiseExceptionWithWrongCredentials()
    {
        Db_Mysqli::resetInstance();
        Db_Mysqli::$Password = \uniqid('wrong_password_');

        $db = new Db_Mysqli();

        $this->expectException(mysqli_sql_exception::class);

        $db->getConnection();
    }

    public function testHasAMysqliConnection()
    {
        static::assertInstanceOf(mysqli::class, $this->db->getConnection());
    }

    public function testEscape()
    {
        $string = '"';

        static::assertNotSame($string, $this->db->escape($string));

        static::assertSame('1', $this->db->escape(1));
        static::assertSame('A', $this->db->escape('A'));
    }

    public function testRaiseExceptionOnAWrongQueryAndReportQuery()
    {
        $query = \sprintf('SELECT 1 FROM %s', \uniqid('non_existing_table_'));

        try {
            $this->db->query($query);
            static::fail('No Db_Exception thrown');
        } catch (Db_Exception $dbException) {
            static::assertContains($query, $dbException->getMessage());

            $previousException = $dbException->getPrevious();

            static::assertInstanceOf(mysqli_sql_exception::class, $previousException);
        }
    }

    public function testNormalQueryBehaviours()
    {
        static::assertFalse(Db_Mysqli::$enableProfiling);

        Db_Mysqli::$enableProfiling = true;

        $this->db->query('
            CREATE TEMPORARY TABLE query_test (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(1) NOT NULL,
                PRIMARY KEY id (id)
            ) ENGINE = MyISAM
        ');

        $result = $this->db->query('INSERT INTO query_test (id, name) VALUES (1, "a"), (9, "b")');

        static::assertArrayHasKey('queries', $GLOBALS);

        Db_Mysqli::$enableProfiling = false;

        static::assertTrue($result);
        static::assertSame(2, $this->db->affected_rows());
        static::assertSame(9, $this->db->last_insert_id());

        $stmt = $this->db->query('SELECT id, name FROM query_test');

        static::assertInstanceOf(mysqli_result::class, $stmt);
        static::assertSame($stmt, $this->db->query_id());
        static::assertSame(2, $this->db->num_rows());

        $values = [];
        while ($this->db->next_record()) {
            static::assertIsString($this->db->f('id'));

            $values[] = $this->db->Record;
        }

        $expected = [
            [
                'id'   => '1',
                'name' => 'a',
            ],
            [
                'id'   => '9',
                'name' => 'b',
            ],
        ];

        static::assertSame($expected, $values);
        static::assertNull($this->db->query_id());

        $metadata = $this->db->metadata('query_test');
        static::assertSame('id', $metadata[0]['name']);
    }

    public function testCannotNextRecordWithoutQuery()
    {
        $this->expectException(Db_Exception::class);

        $this->db->next_record();
    }
}
