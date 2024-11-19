<?php

namespace Tests\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SearchEngine\Query\AndQuery;
use SearchEngine\Query\NotQuery;
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
        $this->parser = new QueryParser('*');
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

    public function testParseSimpleNotQuery()
    {
        $query = $this->parser->parse("hello NOT(world)");
        $expected = new AndQuery([
            new TermQuery("*", "hello"),
            new NotQuery(new TermQuery("*", "world"),),
        ]);

        $this->assertEquals($expected, $query);
    }

    public function testParseSimpleNotAtFirstQuery()
    {
        $query = $this->parser->parse("NOT(world) hello ");
        $expected = new AndQuery([
            new NotQuery(new TermQuery("*", "world")),
            new TermQuery("*", "hello"),
        ]);

        $this->assertEquals($expected, $query);
    }
    public function testParseComplexNotQuery()
    {
        $query = $this->parser->parse("title:hello NOT(world OR other:foo*)");
        $expected = new AndQuery([
            new TermQuery("title", "hello"),
            new NotQuery(
                new OrQuery([
                    new TermQuery("*", "world"),
                    new PrefixQuery("other", "foo"),
                ])
            ),
        ]);

        $this->assertEquals($expected, $query);
    }

    public function testParseTypesStringCast()
    {
        $this->assertEquals('(NOT(*:hello) AND *:world)', $this->parser->parse("NOT(hello) world")->toString());
        $this->assertEquals('((*:world OR other:foo*) AND NOT(title:hello))', $this->parser->parse("(world OR other:foo*) AND NOT(title:hello)")->toString());

        $this->assertEquals('<null>', $this->parser->parse(""));
        $this->assertEquals('*:hello', $this->parser->parse("hello"));
        $this->assertEquals('(*:hello AND *:world)', $this->parser->parse("hello world"));
        $this->assertEquals('(title:hello AND (*:world OR other:foo*))', $this->parser->parse("title:hello (world OR other:foo*)"));

        $this->assertEquals('((*:world OR other:foo*) AND NOT(title:hello))', $this->parser->parse(
            <<<'QUERY'
            (
                (
                    (
                        (
                            (
                                *:world OR 
                                other:foo*
                            ) AND NOT(
                                title:hello
                            )
                        )
                    )
                )
            )
            QUERY)->toString());

    }
}
