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
        $this->parser = new QueryParser('*', new TestSchema());
    }

    public function testParseSimpleAndQuery()
    {
        $query = $this->parser->parse("hello world fun");
        $expected = new AndQuery([
            new TermQuery("*", "hello"),
            new TermQuery("*", "world"),
            new TermQuery("*", "fun"),
        ]);

        $this->assertEquals($expected, $query);
    }

    public function testParseOrWithFieldsQuery()
    {
        $query = $this->parser->parse("hello OR (title:world keyword:fun)");
        $expected = new OrQuery([
            new TermQuery("*", "hello"),
            new AndQuery([
                new TermQuery("title", "world"),
                new TermQuery("keyword", "fun"),
            ]),
        ]);

        $this->assertEquals($expected, $query);
        $query = $this->parser->parse("(hello world) OR fun");
        $expected = new OrQuery([
            new AndQuery([
                new TermQuery("*", "hello"),
                new TermQuery("*", "world"),
            ]),
            new TermQuery("*", "fun"),
        ]);

        $this->assertEquals($expected, $query);
    }

    public function testParsePrefixQuery()
    {
        $query = $this->parser->parse("rend*");
        $expected = new PrefixQuery("*", "rend");

        $this->assertEquals($expected, $query);
    }

    public function testParseMixedComplexQuery()
    {
        $query = $this->parser->parse("title:hello (world OR other:foo*)");
        $expected = new AndQuery([
            new TermQuery("title", "hello"),
            new OrQuery([
                new TermQuery("*", "world"),
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



    public function testParseTypesStringCast()
    {
        $this->assertEquals('<null>', $this->parser->parse(""));
        $this->assertEquals('*:hello', $this->parser->parse("hello"));
        $this->assertEquals('(*:hello AND *:world)', $this->parser->parse("hello world"));
        $this->assertEquals('(title:hello AND (*:world OR other:foo*))', $this->parser->parse("title:hello (world OR other:foo*)"));
    }
}
