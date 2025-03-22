<?php

namespace Tests\Stress;

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
use PHPhinder\Transformer\SymbolTransformer;

#[CoversClass(SearchEngine::class)]
class AliceSearchEngineTest extends TestCase
{
    protected static array $truncated = [
        'json' => false,
        'dbal' => false,
        'redis' => false,
    ];

    public function setUpSearchEngine(string $type): SearchEngine
    {
        $storage = match ($type) {
            'json' => $this->getJsonStorage(),
            'dbal' => $this->getDbalStorage(),
            'redis' => $this->getRedisStorage(),
        };

        if (!self::$truncated[$type]) {
            $storage->truncate();
            self::$truncated[$type] = true;
        }

        $engine = new SearchEngine($storage);
        $this->populateStorage($storage, $engine);

        return $engine;
    }


    #[DataProvider('provideSearches')]
    public function testCreateIndexPerformanceAndSearches(string $type, string $search, float $time, int $matches): void
    {
        $engine = $this->setUpSearchEngine($type);

        $t = microtime(true);
        $results = $engine->search($search);
        $diff = microtime(true) - $t;
        $this->assertLessThan($time, $diff, sprintf('more than %s seconds to search %s', $search, $time));
        $this->assertCount($matches, $results);
    }

    /**
     * @return array<array{string, string, float, int}>
     */
    public static function provideSearches(): array
    {
        return [
            ['json', 'Ali*', 0.15, 403],
            ['json', 'Mabel', 0.05, 4],
            ['json', 'Alice', 0.15, 400],
            ['json', 'said poor Alice', 0.5, 1],
            ['json', 'Alice NOT(wonderland)', 0.15, 395],
            ['json', 'Hatter', 0.05, 57],
            ['json', 'gryphon', 0.15, 55],
            ['json', 'griphon', 0.15, 55],
            ['json', 'winder', 0.3, 35], //winter, wander, wider, wonder
            ['json', '"“I advise you to leave off this minute!”"', 0.2, 1],

            ['dbal', 'Ali*', 0.05, 403],
            ['dbal', 'Mabel', 0.05, 4],
            ['dbal', 'Alice', 0.05, 400],
            ['dbal', 'said poor Alice', 0.05, 1],
            ['dbal', 'Alice NOT(wonderland)', 0.05, 395],
            ['dbal', 'Hatter', 0.05, 57],
            ['dbal', 'gryphon', 0.05, 55],
            ['dbal', 'griphon', 0.05, 55],
            ['dbal', 'winder', 0.05, 35], //winter, wander, wider, wonder
            ['dbal', '"“I advise you to leave off this minute!”"', 0.05, 1],

            ['redis', 'Ali*', 0.15, 403],
            ['redis', 'Mabel', 0.12, 4],
            ['redis', 'Alice', 0.15, 400],
            ['redis', 'said poor Alice', 0.5, 1],
            ['redis', 'Alice NOT(wonderland)', 0.15, 395],
            ['redis', 'Hatter', 0.12, 57],
            ['redis', 'gryphon', 0.15, 55],
            ['redis', 'griphon', 0.15, 55],
            ['redis', 'winder', 0.3, 35], //winter, wander, wider, wonder
            ['redis', '"advise"', 0.4, 1],
            ['redis', '"advise you to leave off this minute!”"', 0.4, 1],
        ];
    }

    private function getJsonStorage(): JsonStorage
    {
        $path = 'var';
        $iso = 'en';
        $schema = new LineSchema(
            new LowerCaseTransformer($iso, StopWordsFilter::class),
            new SymbolTransformer(),
            new StemmerTransformer($iso)
        );
        $tokenizer = new RegexTokenizer();
        $storage = new JsonStorage($path, $schema, $tokenizer);
        return $storage;
    }

    private function getDbalStorage(): DbalStorage
    {
        return new DbalStorage('pdo-sqlite:///var/alice.sqlite', new LineSchema(
            new LowerCaseTransformer('en', StopWordsFilter::class),
            new SymbolTransformer(),
            new StemmerTransformer('en')
        ), new RegexTokenizer());
    }

    private function getRedisStorage(): RedisStorage
    {
        return new RedisStorage('tcp://127.0.0.1:6379', new LineSchema(
            new LowerCaseTransformer('en', StopWordsFilter::class),
            new SymbolTransformer(),
            new StemmerTransformer('en')
        ), new RegexTokenizer());
    }

    private function populateStorage(Storage $storage, SearchEngine $engine): void
    {
        if ($storage->isEmpty()) {
            $handler = fopen(__DIR__ . '/pg11.txt', 'r+');
            if (!$handler) {
                throw new \RuntimeException('Unable to open pg11.txt');
            }

            $chapter = 'unknown';
            $line = 0;
            while (!feof($handler)) {
                $line++;
                if ($line % 100 == 0) {
                    $t = microtime(true);
                    $engine->flush();
                    $this->assertLessThan(20., microtime(true) - $t, 'more than 20 seconds to write on ' . $line);
                }

                $text = fgets($handler);
                if ($text === false) {
                    break;
                }
                $text = trim($text);
                if ($text === '') {
                    continue;
                }
                if (preg_match('/^CHAPTER ([MDCLXVI]+)\.$/', $text, $matches)) {
                    $chapter = $matches[1];
                    continue;
                }

                $engine->addDocument([
                    'line' => $line,
                    'text' => $text,
                    'chapter' => $chapter,
                ]);
            }
            fclose($handler);

            $engine->flush();
        }
    }
}
