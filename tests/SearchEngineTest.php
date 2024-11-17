<?php
namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SearchEngine\Index\JsonStorage;
use SearchEngine\Schema\Schema;
use SearchEngine\SearchEngine;

class TestSchema implements Schema
{
    public int $title = Schema::IS_REQUIRED | Schema::IS_STORED | Schema::IS_INDEXED;
    public int $description = Schema::IS_STORED;
    public int $text =  Schema::IS_INDEXED | Schema::IS_FULLTEXT;
}

#[CoversClass(SearchEngine::class)]
class SearchEngineTest extends TestCase
{
    private SearchEngine $engine;
    public function setUp(): void
    {
        // Example usage
        $storage = new JsonStorage('var', new TestSchema());
        $storage->truncate();
        $this->engine = new SearchEngine($storage);
    }

    public function testSearch(): void
    {

        $this->engine->addDocument([
            'title' => 'hi!',
            'text'=>"hello world! This is a PHP search engine.",
            'description' => 'this is a description'
        ])->addDocument([
            'title' => 'españoles!',
            'text' => "PHP españa makes web development fun to the world.",
            'description' => 'Describe the problems',
        ]);

        $this->engine->flush();
        $results = $this->engine->search("php");
        $this->assertCount(2, $results);
        $results = $this->engine->search("search");
        $this->assertCount(1, $results);
        $results = $this->engine->search("description");
        $this->assertCount(0, $results);
    }

    public function testErrorOnNoRequiredProperty(): void
    {
        $this->engine->addDocument([
            'text'=>"hello world!"
        ]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No `title` key provided for doc {"text":"hello world!"}');
        $this->engine->flush();
        $results = $this->engine->search("php");

        $this->assertCount(500, $results);
    }
}
