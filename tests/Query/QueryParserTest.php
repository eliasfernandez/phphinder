<?php

namespace Tests\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SearchEngine\Query\AndQuery;
use SearchEngine\Query\NullQuery;
use SearchEngine\Query\OrQuery;
use SearchEngine\Query\PrefixQuery;
use SearchEngine\Query\QueryParser;
use SearchEngine\Query\TermQuery;
use Tests\TestSchema;

#[CoversClass(QueryParser::class)]
class QueryParserTest extends TestCase
{
    private QueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new QueryParser('content', new TestSchema());
    }

    public function testParseSimpleAndQuery()
    {
        $query = $this->parser->parse("render shade animate");
        $expected = new AndQuery([
            new TermQuery("content", "render"),
            new TermQuery("content", "shade"),
            new TermQuery("content", "animate"),
        ]);

        $this->assertEquals($expected, $query);
    }

    public function testParseOrWithFieldsQuery()
    {
        $query = $this->parser->parse("render OR (title:shade keyword:animate)");
        $expected = new OrQuery([
            new TermQuery("content", "render"),
            new AndQuery([
                new TermQuery("title", "shade"),
                new TermQuery("keyword", "animate"),
            ]),
        ]);

        $this->assertEquals($expected, $query);
    }

    public function testParsePrefixQuery()
    {
        $query = $this->parser->parse("rend*");
        $expected = new PrefixQuery("content", "rend");

        $this->assertEquals($expected, $query);
    }

    public function testParseMixedComplexQuery()
    {
        $query = $this->parser->parse("title:hello (world OR other:foo*)");
        $expected = new AndQuery([
            new TermQuery("title", "hello"),
            new OrQuery([
                new TermQuery("content", "world"),
                new PrefixQuery("other", "foo"),
            ]),
        ]);

        $this->assertEquals($expected, $query);
    }

    public function testParseEmptyString()
    {
        $query = $this->parser->parse("");
        $expected = new NullQuery();

        $this->assertEquals($expected, $query);
    }
}
