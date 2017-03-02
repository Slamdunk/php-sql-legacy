<?php

declare(strict_types=1);

namespace SlamTest\Db;

use Db_Exception;
use Db_Mysqli;
use PHPUnit\Framework\TestCase;

final class MysqliTest extends TestCase
{
    protected function setUp()
    {
        $parameters = array(
            'Host'                   => '',
            'Port'                   => 0,
            'Socket'                 => '/var/run/mysqld/mysqld-5.6.sock',
            'Database'               => 'tools_ci_test',
            'User'                   => 'tools_ci',
            'Password'               => 'tools_ci',
            'Connection_Charset'     => 'latin1',
        );

        if (getenv('TRAVIS') !== false) {
            $parameters = array(
                'Host'                   => '127.0.0.1',
                'Port'                   => 3306,
                'Socket'                 => '',
                'Database'               => 'tools_ci_test',
                'User'                   => 'travis',
                'Password'               => '',
                'Connection_Charset'     => 'latin1',
            );
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
        Db_Mysqli::$Password = uniqid('wrong_password_');

        $db = new Db_Mysqli();

        $this->expectException('mysqli_sql_exception');

        $db->getConnection();
    }

    public function testHasAMysqliConnection()
    {
        $this->assertInstanceOf('mysqli', $this->db->getConnection());
    }

    public function testEscape()
    {
        $string = '"';

        $this->assertNotSame($string, $this->db->escape($string));

        $this->assertSame('1', $this->db->escape(1));
        $this->assertSame('A', $this->db->escape('A'));
    }

    public function testRaiseExceptionOnAWrongQueryAndReportQuery()
    {
        $query = sprintf('SELECT 1 FROM %s', uniqid('non_existing_table_'));

        try {
            $this->db->query($query);
            $this->fail('No Db_Exception thrown');
        } catch (Db_Exception $dbException) {
            $this->assertContains($query, $dbException->getMessage());

            $previousException = $dbException->getPrevious();

            $this->assertInstanceOf('mysqli_sql_exception', $previousException);
        }
    }

    public function testNormalQueryBehaviours()
    {
        $this->assertFalse(Db_Mysqli::$enableProfiling);

        Db_Mysqli::$enableProfiling = true;

        $this->db->query('
            CREATE TEMPORARY TABLE query_test (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(1) NOT NULL,
                PRIMARY KEY id (id)
            ) ENGINE = MyISAM
        ');

        $result = $this->db->query('INSERT INTO query_test (id, name) VALUES (1, "a"), (9, "b")');

        $this->assertArrayHasKey('queries', $GLOBALS);

        Db_Mysqli::$enableProfiling = false;

        $this->assertTrue($result);
        $this->assertSame(2, $this->db->affected_rows());
        $this->assertSame(9, $this->db->last_insert_id());

        $stmt = $this->db->query('SELECT id, name FROM query_test');

        $this->assertInstanceOf('mysqli_result', $stmt);
        $this->assertSame($stmt, $this->db->query_id());
        $this->assertSame(2, $this->db->num_rows());

        $values = array();
        while ($this->db->next_record()) {
            $this->assertInternalType('string', $this->db->f('id'));

            $values[] = $this->db->Record;
        }

        $expected = array(
            array(
                'id' => '1',
                'name' => 'a',
            ),
            array(
                'id' => '9',
                'name' => 'b',
            ),
        );

        $this->assertSame($expected, $values);
        $this->assertNull($this->db->query_id());

        $metadata = $this->db->metadata('query_test');
        $this->assertSame('id', $metadata[0]['name']);
    }

    public function testCannotNextRecordWithoutQuery()
    {
        $this->expectException('Db_Exception');

        $this->db->next_record();
    }
}
