<?php

namespace Tests\Unit\Transformer;

use PHPhinder\Transformer\StemmerTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(StemmerTransformer::class)]
class StemmerTransformerTest extends TestCase
{
    private StemmerTransformer $transformer;

    public function setUp(): void
    {
        parent::setUp();
        $this->transformer = new StemmerTransformer();
    }

    #[DataProvider('provideStemmableWords')]
    #[TestDox('Converting $original results in $expected')]
    public function testTransform(string $original, string $expected): void
    {
        $this->assertSame($expected, $this->transformer->apply($original));
    }

    /**
     * @return array<array{string, string}>
     */
    public static function provideStemmableWords(): array
    {
        return [
            ['accompanied','accompani'],
            ['witnesses','wit'],
            ['write','write'],
            ['test','test'],
            ['goat', 'goat'],
        ];
    }
}
