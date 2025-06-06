<?php

namespace Tests\Unit\Transformer;

use PHPhinder\Transformer\StopWordsFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(StopWordsFilter::class)]
class StopWordsFilterTest extends TestCase
{
    private StopWordsFilter $filter;

    public function setUp(): void
    {
        parent::setUp();
        $this->filter = new StopWordsFilter();
    }

    #[DataProvider('provideStopWords')]
    #[TestDox('Filtering $original results in $expected')]
    public function testTransform(string $original, bool $expected): void
    {
        $this->assertSame($expected, $this->filter->allow($original));
    }

    public function testNonValidIsoTransform(): void
    {
        $this->assertSame(false, $this->filter->allow('a'));

        $filter = new StopWordsFilter('foo');
        $this->assertSame(true, $filter->allow('a'));
    }

    /**
     * @return array<array{string, bool}>
     */
    public static function provideStopWords(): array
    {
        return [
            ['a', false],
            ['be', false],
            ['call', false],
            ['de', false],
            ['each', false],
            ['few', false],
            ['get', false],
            ['had', false],
            ['ie', false],
            ['keep', false],
            ['last', false],
            ['made', false],
            ['name', false],
            ['of', false],
            ['part', false],
            ['rather', false],
            ['same', false],
            ['take', false],
            ['un', false],
            ['very', false],
            ['was', false],
            ['yet', false],
            ['test', true],
            ['españa', true],
            ['🤗', true],
            ['goat', true],
            ['the', false],
        ];
    }
}
