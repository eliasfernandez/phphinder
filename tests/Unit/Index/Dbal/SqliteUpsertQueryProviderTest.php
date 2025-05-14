<?php

namespace Tests\Unit\Index\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\SQLite3;
use PHPhinder\Index\Dbal\SqliteUpsertQueryProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqliteUpsertQueryProvider::class)]
class SqliteUpsertQueryProviderTest extends TestCase
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
}
