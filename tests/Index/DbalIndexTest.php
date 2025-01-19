<?php

namespace Tests\Index;

use PHPhinder\Index\DbalIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DbalIndex::class)]
class DbalIndexTest extends TestCase
{
    public function testInsertMultiple()
    {
        $index = new DbalIndex('pdo-sqlite:///var/test.sqlite', 'test_table');
        $index->create(['s', 'column_1', 'column_2']);
        $index->insertMultiple(
            ['s', 'column_1', 'column_2'],
            [
                ['s' => 1, 'column_1' => 'test 1', 'column_2' => 'test 2'],
                ['s' => 2, 'column_1' => 'test 3', 'column_2' => 'test 4']
            ]
        );
        $index->insertMultiple(
            ['s', 'column_1', 'column_2'],
            [
                ['s' => 3, 'column_1' => 'test 5', 'column_2' => 'test 6'],
                ['s' => 1, 'column_1' => 'test 7', 'column_2' => 'test 8']
            ]
        );
        $this->assertSame([
            ['s' => 1, 'column_1' => 'test 7', 'column_2' => 'test 8'],
            ['s' => 2, 'column_1' => 'test 3', 'column_2' => 'test 4'],
            ['s' => 3, 'column_1' => 'test 5', 'column_2' => 'test 6'],
        ], iterator_to_array($index->findAll()));
    }
    public function tearDown(): void
    {
        unlink('var/test.sqlite');
    }
}
