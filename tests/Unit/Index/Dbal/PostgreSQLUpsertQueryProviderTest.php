<?php

namespace Tests\Unit\Index\Dbal;

use Doctrine\DBAL\Connection;
use PHPhinder\Index\Dbal\PostgreSQLUpsertQueryProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PostgreSQLUpsertQueryProvider::class)]
class PostgreSQLUpsertQueryProviderTest extends TestCase
{
    public function test(): void
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
