<?php

namespace Tests\Stress;

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
    private SearchEngine $engine;
    public function setUp(): void
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

        $this->engine = new SearchEngine($storage);
        //$storage->truncate();
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
                    $this->engine->flush();
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

                $this->engine->addDocument([
                    'line' => $line,
                    'text' => $text,
                    'chapter' => $chapter,
                ]);
            }
            fclose($handler);

            $this->engine->flush();
        }
    }


    #[DataProvider('provideSearches')]
    public function testCreateIndexPerformanceAndSearches(string $search, float $time, int $matches): void
    {
        $t = microtime(true);
        $results = $this->engine->search($search);
        $diff = microtime(true) - $t;
        $this->assertLessThan($time, $diff, sprintf('more than %s seconds to search %s', $search, $time));
        $this->assertCount($matches, $results);
    }

    /**
     * @return array<array{string, float, int}>
     */
    public static function provideSearches(): array
    {
        return [
            ['Ali*', 0.15, 403],
            ['Mabel', 0.05, 4],
            ['Alice', 0.15, 400],
            ['said poor Alice', 0.5, 1],
            ['Alice NOT(wonderland)', 0.15, 395],
            ['Hatter', 0.05, 57],
            ['gryphon', 0.15, 55],
            ['griphon', 0.15, 55],
            ['winder', 0.3, 36], //winter, wander, wider, wonder
        ];
    }
}
