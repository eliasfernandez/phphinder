<?php

namespace Tests\Transformer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use SearchEngine\Transformer\LowerCaseTransformer;
use PHPUnit\Framework\TestCase;
use SearchEngine\Transformer\StemmerTransformer;

#[CoversClass(StemmerTransformer::class)]
class StemmerTransformerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->transformer = new StemmerTransformer();
    }

    #[DataProvider('provideStemmableWords')]
    #[TestDox('Converting $original results in $expected')]
    public function testTransform($original, $expected): void
    {
        $this->assertSame($expected, $this->transformer->apply($original));
    }

    public static function provideStemmableWords(): array
    {
        return [
            ['accompanied','accompani'],
            ['witnesses','wit'],
            ['write','write'],
            ['test','test']
        ];
    }
}
