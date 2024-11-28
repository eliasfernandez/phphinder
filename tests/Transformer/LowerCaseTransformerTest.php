<?php

namespace Tests\Transformer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use SearchEngine\Transformer\LowerCaseTransformer;
use PHPUnit\Framework\TestCase;

#[CoversClass(LowerCaseTransformer::class)]
class LowerCaseTransformerTest extends TestCase
{
    private LowerCaseTransformer $transformer;

    public function setUp(): void
    {
        parent::setUp();
        $this->transformer = new LowerCaseTransformer();
    }

    #[DataProvider('provideLowerCaseData')]
    #[TestDox('Converting $original results in $expected')]
    public function testTransform(string $original, string $expected): void
    {
        $this->assertSame($expected, $this->transformer->apply($original));
    }

    /**
     * @return array<array{string, string}>
     */
    public static function provideLowerCaseData(): array
    {
        return [
            ['Hello','hello'],
            ['ESPAÑA','españa'],
            ['title','title'],
            ['🤗','🤗']
        ];
    }
}
