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

        $this->engine = new SearchEngine($storage, $schema, $tokenizer);
        $this->engine->addDocument([
            'title' => 'hi!',
            'text' => "hello world! This is a PHP search engine.",
            'description' => 'this is a description'
        ])->addDocument([
            'title' => 'españoles!',
            'text' => "PHP españa makes web development fun to the world.",
            'description' => 'Describe the problems',
        ])->addDocument([
            'title' => 'hi!',
            'text' => "hello search! This is the minimal PHP search engine.",
            'description' => 'this is a description'
        ]);

        $this->engine->flush();
    }

    public function testSearchAnd(): void
    {
        $results = $this->engine->search('hello world');
        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertCount(2, $results[1]['terms']);
        $this->assertCount(1, $results[1]['indices']);
        $this->assertTrue($results[1]['fulltext']);
    }

    public function testSearchOr(): void
    {
        $results = $this->engine->search('hello OR world');
        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        $this->assertCount(2, $results[1]['terms']);
        $this->assertCount(1, $results[2]['terms']);
        $this->assertCount(1, $results[1]['indices']);
        $this->assertFalse($results[1]['fulltext']);
        $this->assertFalse($results[2]['fulltext']);
    }

    public function testSearchParentheses(): void
    {
        $results = $this->engine->search('(hello world) OR fun');
        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertCount(2, $results[1]['terms']);
        $this->assertCount(1, $results[2]['terms']);
        $this->assertCount(1, $results[1]['indices']);
        $this->assertFalse($results[1]['fulltext']);
        $this->assertFalse($results[2]['fulltext']);
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
}
