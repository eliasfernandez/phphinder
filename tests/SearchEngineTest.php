<?php

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPhinder\Index\JsonStorage;
use PHPhinder\SearchEngine;
use PHPhinder\Token\RegexTokenizer;
use PHPhinder\Transformer\LowerCaseTransformer;
use PHPhinder\Transformer\StemmerTransformer;
use PHPhinder\Transformer\StopWordsFilter;

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

        $this->engine = new SearchEngine($storage);
        $this->engine->addDocument([
            '_id' => 1,
            'title' => 'Cat animal',
            'text' => "Meow world! This is a PHP search engine.",
            'description' => 'this is a description'
        ])->addDocument([
            '_id' => 2,
            'title' => 'Dog',
            'text' => "Bark Bark! PHPhinder makes search development fun to the world.",
            'description' => 'Describe the problems',
        ])->addDocument([
            '_id' => 3,
            'title' => 'Snake',
            'text' => "szee szee! This is the minimal PHP search engine for the animal world.",
            'description' => 'this is a description'
        ]);

        $this->engine->flush();
    }

    public function testSearchAnd(): void
    {
        $results = $this->engine->search('search engine');
        $this->assertCount(2, $results);
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(1, $results[1]->getIndices());
        $this->assertTrue($results[0]->isFulltext());
    }

    public function testSearchOr(): void
    {
        $results = $this->engine->search('search OR engine');
        $this->assertCount(3, $results);
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(1, $results[2]->getTerms());
        $this->assertCount(1, $results[1]->getIndices());
        $this->assertFalse($results[1]->isFulltext());
        $this->assertFalse($results[2]->isFulltext());
    }

    public function testSearchParentheses(): void
    {
        $results = $this->engine->search('(search engine) OR fun');
        $this->assertCount(3, $results);
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(1, $results[2]->getTerms());
        $this->assertCount(1, $results[0]->getIndices());
        $this->assertFalse($results[0]->isFulltext());
        $this->assertFalse($results[1]->isFulltext());
    }

    public function testSearchNot(): void
    {
        $results = $this->engine->search('world NOT(engine)');

        $this->assertCount(1, $results);
        $this->assertCount(1, $results[0]->getTerms());
        $this->assertCount(1, $results[0]->getIndices());
        $this->assertFalse($results[0]->isFulltext());
    }

    public function testSearchNotAtFirst(): void
    {
        $results = $this->engine->search('NOT(engine) bark');
        $this->assertCount(1, $results);
        $this->assertCount(1, $results[0]->getTerms());
        $this->assertCount(1, $results[0]->getIndices());
        $this->assertFalse($results[0]->isFulltext());
    }

    public function testFindDocsByIndex(): void
    {
        $results = $this->engine->findDocsByIndex("php");
        $this->assertCount(2, $results['text']);
        $this->assertCount(0, $results['title']);

        $results = $this->engine->findDocsByIndex("search");
        $this->assertCount(3, $results['text']);
        $this->assertCount(0, $results['title']);

        $results = $this->engine->findDocsByIndex("engine");
        $this->assertCount(2, $results['text']);

        $results = $this->engine->findDocsByIndex("cat");
        $this->assertCount(0, $results['text']);
        $this->assertCount(1, $results['title']);

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
        $results = $this->engine->search('animal world');

        $this->assertCount(2, $results);
        $this->assertCount(2, $results[0]->getTerms());
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(1, $results[0]->getIndices());
        $this->assertCount(2, $results[1]->getIndices());
        $this->assertTrue($results[0]->isFulltext());
        $this->assertFalse($results[1]->isFulltext());
        $this->assertEquals(16., $results[0]->getWeight());
        $this->assertEquals(10., $results[1]->getWeight());
    }

    public function testDocumentationExample(): void
    {
        $storage = new JsonStorage('var');
        $engine = new SearchEngine($storage);

        $storage->truncate();

        $engine->addDocument(['_id' => 1, 'title' => 'Hi', 'text' => 'Hello world!']);
        $engine->flush();
        $results = $engine->search('Hello');
        $this->assertEquals('Hi', $results[1]->getDocument()['title']);
    }

    public function testAddUniqueDocumentsOverridePreviousOne(): void
    {
        $this->engine->addDocument([
            '_id' => 1,
            'title' => 'Cow',
            'text' => "Mooh world! This is a PHP search engine.",
            'description' => 'this is a description'
        ]);
        $this->engine->flush();

        $results = $this->engine->search('meow');
        $this->assertCount(0, $results);

        $results = $this->engine->search('mooh');
        $this->assertCount(1, $results);
    }
}
