<?php

namespace Index\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\SQLite3;
use PHPhinder\Index\Dbal\MariaDbUpsertQueryProvider;
use PHPhinder\Index\Dbal\PostgreSQLUpsertQueryProvider;
use PHPhinder\Index\Dbal\SqliteUpsertQueryProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqliteUpsertQueryProvider::class | PostgreSQLUpsertQueryProvider::class | MariaDBUpsertQueryProvider::class)]
class UpsertQueryProviderTest extends TestCase
{
    public function testSqlite(): void
    {
        $conn = new Connection(['memory' => true], new SQLite3\Driver());
        $provider = new SqliteUpsertQueryProvider($conn);
        $sql = $provider->getUpsertBatchQuery('table', ['s', 'column_1', 'column_2'], [
            ['s' => 1, 'column_1' => 'test 1', 'column_2' => 'test 2'],
            ['s' => 2, 'column_1' => 'test 3', 'column_2' => 'test 4']
        ]);

        $this->assertSame(<<<SQL
        INSERT OR REPLACE INTO table (s, column_1, column_2) 
            VALUES ('1', 'test 1', 'test 2'), ('2', 'test 3', 'test 4')
        SQL, $sql);
    }

    public function testMysql(): void
    {
        $conn = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->getMock();

        $conn->method('quote')
            ->willReturnCallback(fn ($item) => "'{$item}'");

        $provider = new MariaDbUpsertQueryProvider($conn);
        $sql = $provider->getUpsertBatchQuery('table', ['s', 'column_1', 'column_2'], [
            ['s' => 1, 'column_1' => 'test 1', 'column_2' => 'test 2'],
            ['s' => 2, 'column_1' => 'test 3', 'column_2' => 'test 4']
        ]);

        $this->assertSame(<<<SQL
            INSERT INTO table (s, column_1, column_2) 
                VALUES ('1', 'test 1', 'test 2'), ('2', 'test 3', 'test 4') AS excluded 
                ON DUPLICATE KEY 
                UPDATE s = excluded.s, column_1 = excluded.column_1, column_2 = excluded.column_2
            SQL, $sql);
    }

    public function testPsql(): void
    {
        $conn = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->getMock();

        $conn->method('quote')
            ->willReturnCallback(fn ($item) => "'{$item}'");

        $provider = new PostgreSQLUpsertQueryProvider($conn);
        $sql = $provider->getUpsertBatchQuery('table', ['s', 'column_1', 'column_2'], [
            ['s' => 1, 'column_1' => 'test 1', 'column_2' => 'test 2'],
            ['s' => 2, 'column_1' => 'test 3', 'column_2' => 'test 4']
        ]);

        $this->assertSame(<<<SQL
            INSERT INTO table (s, column_1, column_2) 
                VALUES ('1', 'test 1', 'test 2'), ('2', 'test 3', 'test 4') 
                ON CONFLICT (s) 
                DO UPDATE SET s = excluded.s, column_1 = excluded.column_1, column_2 = excluded.column_2
            SQL, $sql);
    }
}
