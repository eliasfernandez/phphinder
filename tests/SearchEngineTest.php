<?php

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SearchEngine\Index\JsonStorage;
use SearchEngine\SearchEngine;
use SearchEngine\Token\RegexTokenizer;
use SearchEngine\Transformer\LowerCaseTransformer;
use SearchEngine\Transformer\StemmerTransformer;
use SearchEngine\Transformer\StopWordsFilter;

#[CoversClass(SearchEngine::class)]
class SearchEngineTest extends TestCase
{
    private SearchEngine $engine;
    public function setUp(): void
    {
        $path = 'var';
        $iso = 'en';
        $schema = new TestSchema(
            new LowerCaseTransformer($iso, StopWordsFilter::class),
            new StemmerTransformer($iso)
        );
        $tokenizer = new RegexTokenizer();
        $storage = new JsonStorage($path, $schema, $tokenizer);
        $storage->truncate();

        $this->engine = new SearchEngine($storage, $schema);
        $this->engine->addDocument([
            'title' => 'hi!',
            'text' => "hello world! This is a PHP search engine.",
            'description' => 'this is a description'
        ])->addDocument([
            'title' => 'hello!',
            'text' => "PHP españa makes web development fun to the world.",
            'description' => 'Describe the problems',
        ])->addDocument([
            'title' => 'hi!',
            'text' => "hello search! This is the minimal PHP search engine for the world.",
            'description' => 'this is a description'
        ]);

        $this->engine->flush();
    }

    public function testSearchAnd(): void
    {
        $results = $this->engine->search('hello world');
        $this->assertCount(3, $results);
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(2, $results[1]->getIndices());
        $this->assertTrue($results[0]->isFulltext());
    }

    public function testSearchOr(): void
    {
        $results = $this->engine->search('hello OR world');
        $this->assertCount(3, $results);
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(2, $results[2]->getTerms());
        $this->assertCount(1, $results[1]->getIndices());
        $this->assertFalse($results[1]->isFulltext());
        $this->assertFalse($results[2]->isFulltext());
    }

    public function testSearchParentheses(): void
    {
        $results = $this->engine->search('(hello world) OR fun');
        $this->assertCount(3, $results);
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(2, $results[2]->getTerms());
        $this->assertCount(1, $results[0]->getIndices());
        $this->assertFalse($results[0]->isFulltext());
        $this->assertFalse($results[1]->isFulltext());
    }

    public function testSearchNot(): void
    {
        $results = $this->engine->search('hello NOT(engine)');

        $this->assertCount(1, $results);
        $this->assertCount(1, $results[0]->getTerms());
        $this->assertCount(1, $results[0]->getIndices());
        $this->assertFalse($results[0]->isFulltext());
    }

    public function testSearchNotAtFirst(): void
    {
        $results = $this->engine->search('NOT(engine) hello');
        $this->assertCount(1, $results);
        $this->assertCount(1, $results[0]->getTerms());
        $this->assertCount(1, $results[0]->getIndices());
        $this->assertFalse($results[0]->isFulltext());
    }

    public function testFindDocsByIndex(): void
    {
        $results = $this->engine->findDocsByIndex("php");
        $this->assertCount(3, $results['text']);
        $this->assertCount(0, $results['title']);

        $results = $this->engine->findDocsByIndex("search");
        $this->assertCount(2, $results['text']);
        $this->assertCount(0, $results['title']);

        $results = $this->engine->findDocsByIndex("engine");
        $this->assertCount(2, $results['text']);

        $results = $this->engine->findDocsByIndex("HI");
        $this->assertCount(0, $results['text']);
        $this->assertCount(2, $results['title']);


        $results = $this->engine->findDocsByIndex("description");
        $this->assertCount(0, $results['text']);
        $this->assertCount(0, $results['title']);
    }

    public function testErrorOnNoRequiredProperty(): void
    {
        $this->engine->addDocument(['text' => "hello world!"]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No `title` key provided for doc {"text":"hello world!"}');
        $this->engine->flush();
        $this->engine->findDocsByIndex("php");
    }

    public function testSortedResults(): void
    {
        $results = $this->engine->search('hello world');
        $this->assertCount(3, $results);
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(1, $results[2]->getIndices());
        $this->assertTrue($results[0]->isFulltext());
    }
}
