<?php

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SearchEngine\Index\JsonStorage;
use SearchEngine\SearchEngine;
use SearchEngine\Transformer\LowerCaseTransformer;
use SearchEngine\Transformer\StemmerTransformer;
use SearchEngine\Transformer\StopWordsFilter;

#[CoversClass(SearchEngine::class)]
class SearchEngineTest extends TestCase
{
    private SearchEngine $engine;
    public function setUp(): void
    {
        $storage = new JsonStorage('var', new TestSchema(
            new LowerCaseTransformer('en', StopWordsFilter::class),
            new StemmerTransformer('en')
        ));

        $storage->truncate();
        $this->engine = new SearchEngine($storage);
    }

    public function testSearch(): void
    {
        $this->engine->addDocument([
            'title' => 'hi!',
            'text' => "hello world! This is a PHP search engine.",
            'description' => 'this is a description'
        ])->addDocument([
            'title' => 'españoles!',
            'text' => "PHP españa makes web development fun to the world.",
            'description' => 'Describe the problems',
        ]);

        $this->engine->flush();

        $results = $this->engine->search("php");
        $this->assertCount(2, $results['text']);
        $this->assertCount(0, $results['title']);

        $results = $this->engine->search("search");
        $this->assertCount(1, $results['text']);
        $this->assertCount(0, $results['title']);

        $results = $this->engine->search("engine");
        $this->assertCount(1, $results['text']);

        $results = $this->engine->search("HI");
        $this->assertCount(0, $results['text']);
        $this->assertCount(1, $results['title']);


        $results = $this->engine->search("description");
        $this->assertCount(0, $results['text']);
        $this->assertCount(0, $results['title']);
    }

    public function testErrorOnNoRequiredProperty(): void
    {
        $this->engine->addDocument(['text' => "hello world!"]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No `title` key provided for doc {"text":"hello world!"}');
        $this->engine->flush();
        $this->engine->search("php");
    }
}
