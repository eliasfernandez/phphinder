<?php

namespace Tests\Query;

use Couchbase\QueryException;
use PHPhinder\Query\FullTextQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPhinder\Query\AndQuery;
use PHPhinder\Query\NotQuery;
use PHPhinder\Query\NullQuery;
use PHPhinder\Query\OrQuery;
use PHPhinder\Query\PrefixQuery;
use PHPhinder\Query\QueryParser;
use PHPhinder\Query\TermQuery;
use Tests\TestSchema;

#[CoversClass(QueryParser::class)]
class QueryParserTest extends TestCase
{
    private QueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new QueryParser('*');
    }

    public function testParseSimpleAndQuery(): void
    {
        $query = $this->parser->parse("hello world fun");
        $expected = new AndQuery([
            new TermQuery("*", "hello"),
            new TermQuery("*", "world"),
            new TermQuery("*", "fun"),
        ]);

        $this->assertEquals($expected, $query);
    }

    public function testParseOrWithFieldsQuery(): void
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

    public function testParsePrefixQuery(): void
    {
        $query = $this->parser->parse("rend*");
        $expected = new PrefixQuery("*", "rend");

        $this->assertEquals($expected, $query);
    }

    public function testParseMixedComplexQuery(): void
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

    public function testParseEmptyString(): void
    {
        $query = $this->parser->parse("");
        $expected = new NullQuery('Empty Query');

        $this->assertEquals($expected, $query);
    }

    public function testParseSimpleNotQuery(): void
    {
        $query = $this->parser->parse("hello NOT(world)");
        $expected = new AndQuery([
            new TermQuery("*", "hello"),
            new NotQuery([new TermQuery("*", "world")]),
        ]);

        $this->assertEquals($expected, $query);
    }

    public function testParseSimpleNotAtFirstQuery(): void
    {
        $query = $this->parser->parse("NOT(world) hello ");
        $expected = new AndQuery([
            new NotQuery([new TermQuery("*", "world")]),
            new TermQuery("*", "hello"),
        ]);

        $this->assertEquals($expected, $query);
    }
    public function testParseComplexNotQuery(): void
    {
        $query = $this->parser->parse("title:hello NOT(world OR other:foo*)");
        $expected = new AndQuery([
            new TermQuery("title", "hello"),
            new NotQuery([
                new OrQuery([
                    new TermQuery("*", "world"),
                    new PrefixQuery("other", "foo"),
                ])
            ]),
        ]);

        $this->assertEquals($expected, $query);
    }

    public function testParseFullTextQuery(): void
    {
        $query = $this->parser->parse('"Animal instict"');
        $expected = new FullTextQuery("*", 'Animal instict');

        $this->assertEquals($expected, $query);
    }

    public function testParseTypesStringCast(): void
    {
        $this->assertEquals('(*:hello AND *:world)', $this->parser->parse("'hello world'"));
        $this->assertEquals('(NOT(*:hello) AND *:world)', $this->parser->parse("NOT(hello) world")->toString());
        $this->assertEquals('((*:world OR other:foo*) AND NOT(title:hello))', $this->parser->parse("(world OR other:foo*) AND NOT(title:hello)")->toString());

        $this->assertEquals('<null> Empty Query', $this->parser->parse("")->toString());
        $this->assertEquals('*:hello', $this->parser->parse("hello"));
        $this->assertEquals('(*:hello AND *:world)', $this->parser->parse("hello world"));
        $this->assertEquals('*:"hello world"', $this->parser->parse('"hello world"'));
        $this->assertEquals('(*:hello AND *:world)', $this->parser->parse('hello world"'));
        $this->assertEquals('(*:hello AND *:world)', $this->parser->parse('"hello world'));
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
            QUERY
        )->toString());
    }
}
