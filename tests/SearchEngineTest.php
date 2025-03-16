<?php

namespace Tests;

use PHPhinder\Index\DbalStorage;
use PHPhinder\Index\RedisStorage;
use PHPhinder\Index\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    #[DataProvider('searchEnginesProvider')]
    public function testSearchAnd(SearchEngine $engine): void
    {
        $results = $engine->search('search engine');
        $this->assertCount(2, $results);
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(1, $results[1]->getIndices());
        $this->assertTrue($results[0]->isFulltext());
    }

    #[DataProvider('searchEnginesProvider')]
    public function testSearchOr(SearchEngine $engine): void
    {
        $results = $engine->search('search OR engine');
        $this->assertCount(3, $results);
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(1, $results[2]->getTerms());
        $this->assertCount(1, $results[1]->getIndices());
        $this->assertFalse($results[1]->isFulltext());
        $this->assertFalse($results[2]->isFulltext());
    }

    #[DataProvider('searchEnginesProvider')]
    public function testSearchParentheses(SearchEngine $engine): void
    {
        $results = $engine->search('(search engine) OR fun');
        $this->assertCount(3, $results);
        $this->assertCount(2, $results[1]->getTerms());
        $this->assertCount(1, $results[2]->getTerms());
        $this->assertCount(1, $results[0]->getIndices());
        $this->assertFalse($results[0]->isFulltext());
        $this->assertFalse($results[1]->isFulltext());
    }

    #[DataProvider('searchEnginesProvider')]
    public function testSearchNot(SearchEngine $engine): void
    {
        $results = $engine->search('world NOT(engine)');

        $this->assertCount(1, $results);
        $this->assertCount(1, $results[0]->getTerms());
        $this->assertCount(1, $results[0]->getIndices());
        $this->assertFalse($results[0]->isFulltext());
    }

    #[DataProvider('searchEnginesProvider')]
    public function testSearchNotAtFirst(SearchEngine $engine): void
    {
        $results = $engine->search('NOT(engine) bark');
        $this->assertCount(1, $results);
        $this->assertCount(1, $results[0]->getTerms());
        $this->assertCount(1, $results[0]->getIndices());
        $this->assertFalse($results[0]->isFulltext());
    }

    #[DataProvider('searchEnginesProvider')]
    public function testFindDocsByIndex(SearchEngine $engine): void
    {
        $results = $engine->findDocsByIndex("php");
        $this->assertCount(2, $results['text']);
        $this->assertCount(0, $results['title']);

        $results = $engine->findDocsByIndex("search");
        $this->assertCount(3, $results['text']);
        $this->assertCount(0, $results['title']);

        $results = $engine->findDocsByIndex("engine");
        $this->assertCount(2, $results['text']);

        $results = $engine->findDocsByIndex("cat");
        $this->assertCount(0, $results['text']);
        $this->assertCount(1, $results['title']);

        $results = $engine->findDocsByIndex("description");
        $this->assertCount(0, $results['text']);
        $this->assertCount(0, $results['title']);
    }

    #[DataProvider('searchEnginesProvider')]
    public function testErrorOnNoRequiredProperty(SearchEngine $engine): void
    {
        $engine->addDocument(['text' => "hello world!"]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No `title` key provided for doc {"text":"hello world!"}');
        $engine->flush();
        $engine->findDocsByIndex("php");
    }

    #[DataProvider('searchEnginesProvider')]
    public function testSortedResults(SearchEngine $engine): void
    {
        $results = $engine->search('animal world');

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

    #[DataProvider('searchEnginesProvider')]
    public function testAddUniqueDocumentsOverridePreviousOne(SearchEngine $engine): void
    {
        $engine->addDocument([
            '_id' => 1,
            'title' => 'Cow',
            'text' => "Mooh world! This is a PHP search engine.",
            'description' => 'this is a description'
        ]);
        $engine->flush();

        $results = $engine->search('meow');
        $this->assertCount(0, $results);

        $results = $engine->search('mooh');
        $this->assertCount(1, $results);
    }

    #[DataProvider('searchEnginesProvider')]
    public function testSearchTypo(SearchEngine $engine): void
    {
        $results = $engine->search('phphender');

        $this->assertCount(1, $results);
        $this->assertCount(1, $results[2]->getTerms());
        $this->assertCount(1, $results[2]->getIndices());
        $this->assertFalse($results[2]->isFulltext());

        $results = $engine->search('develep');

        $this->assertCount(1, $results);
        $this->assertCount(1, $results[2]->getTerms());
        $this->assertCount(1, $results[2]->getIndices());
        $this->assertFalse($results[2]->isFulltext());
    }


    #[DataProvider('searchEnginesProvider')]
    public function testSearchFulltext(SearchEngine $engine): void
    {
        $results = $engine->search('"search engine"');

        $this->assertCount(2, $results);
        $this->assertCount(1, $results[1]->getTerms());
        $this->assertCount(1, $results[1]->getIndices());
        $this->assertTrue($results[1]->isFulltext());
    }

    /**
     * @return array<string, array<SearchEngine>>
     */
    public static function searchEnginesProvider(): array
    {
        $storage = new JsonStorage('var', new TestSchema(
            new LowerCaseTransformer('en', StopWordsFilter::class),
            new StemmerTransformer('en')
        ), new RegexTokenizer());
        $jsonEngine = self::createSearchEngine($storage);

        $storage = new DbalStorage('pdo-sqlite:///var/test.sqlite', new TestSchema(
            new LowerCaseTransformer('en', StopWordsFilter::class),
            new StemmerTransformer('en')
        ), new RegexTokenizer());
        $dbalEngine = self::createSearchEngine($storage);

        $storage = new RedisStorage('tcp://127.0.0.1:6379', new TestSchema(
            new LowerCaseTransformer('en', StopWordsFilter::class),
            new StemmerTransformer('en')
        ), new RegexTokenizer());
        $redisEngine = self::createSearchEngine($storage);

        return [
            'redis' => [$redisEngine],
            'json' => [$jsonEngine],
            'dbal' => [$dbalEngine],
        ];
    }

    public static function createSearchEngine(Storage $storage): SearchEngine
    {
        $storage->truncate();

        $jsonEngine = new SearchEngine($storage);
        $jsonEngine->addDocument([
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

        $jsonEngine->flush();
        return $jsonEngine;
    }
}
