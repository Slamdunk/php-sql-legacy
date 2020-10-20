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
    private Db_Mysqli $db;

    protected function setUp(): void
    {
        Db_Mysqli::resetInstance();
        Db_Mysqli::$Host               = '127.0.0.1';
        Db_Mysqli::$Port               = 3306;
        Db_Mysqli::$Socket             = '';
        Db_Mysqli::$Database           = 'sql_legacy';
        Db_Mysqli::$User               = 'root';
        Db_Mysqli::$Password           = 'root_password';
        Db_Mysqli::$Connection_Charset = 'latin1';

        $this->db = new Db_Mysqli();
    }

    public function testRaiseExceptionWithWrongCredentials(): void
    {
        Db_Mysqli::resetInstance();
        Db_Mysqli::$Password = \uniqid('wrong_password_');

        $db = new Db_Mysqli();

        $this->expectException(mysqli_sql_exception::class);

        $db->getConnection();
    }

    public function testHasAMysqliConnection(): void
    {
        self::assertInstanceOf(mysqli::class, $this->db->getConnection());
    }

    public function testEscape(): void
    {
        $string = '"';

        self::assertNotSame($string, $this->db->escape($string));

        self::assertSame('1', $this->db->escape(1));
        self::assertSame('A', $this->db->escape('A'));
    }

    public function testRaiseExceptionOnAWrongQueryAndReportQuery(): void
    {
        $query = \sprintf('SELECT 1 FROM %s', \uniqid('non_existing_table_'));

        try {
            $this->db->query($query);
            self::fail('No Db_Exception thrown');
        } catch (Db_Exception $dbException) {
            self::assertStringContainsString($query, $dbException->getMessage());

            $previousException = $dbException->getPrevious();

            self::assertInstanceOf(mysqli_sql_exception::class, $previousException);
        }
    }

    public function testNormalQueryBehaviours(): void
    {
        self::assertFalse(Db_Mysqli::$enableProfiling);

        Db_Mysqli::$enableProfiling = true;

        $this->db->query('
            CREATE TEMPORARY TABLE query_test (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(1) NOT NULL,
                PRIMARY KEY id (id)
            ) ENGINE = MyISAM
        ');

        $result = $this->db->query('INSERT INTO query_test (id, name) VALUES (1, "a"), (9, "b")');

        self::assertArrayHasKey('queries', $GLOBALS);

        Db_Mysqli::$enableProfiling = false;

        self::assertTrue($result);
        self::assertSame(2, $this->db->affected_rows());
        self::assertSame(9, $this->db->last_insert_id());

        $stmt = $this->db->query('SELECT id, name FROM query_test');

        self::assertInstanceOf(mysqli_result::class, $stmt);
        self::assertSame($stmt, $this->db->query_id());
        self::assertSame(2, $this->db->num_rows());

        $values = [];
        while ($this->db->next_record()) {
            self::assertIsString($this->db->f('id'));

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

        self::assertSame($expected, $values);
        self::assertNull($this->db->query_id());

        $metadata = $this->db->metadata('query_test');
        self::assertSame('id', $metadata[0]['name']);
    }

    public function testCannotNextRecordWithoutQuery(): void
    {
        $this->expectException(Db_Exception::class);

        $this->db->next_record();
    }
}
