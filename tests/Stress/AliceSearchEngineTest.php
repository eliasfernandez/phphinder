<?php

namespace Tests\Stress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SearchEngine\Index\JsonStorage;
use SearchEngine\SearchEngine;
use SearchEngine\Token\RegexTokenizer;
use SearchEngine\Transformer\LowerCaseTransformer;
use SearchEngine\Transformer\StemmerTransformer;
use SearchEngine\Transformer\StopWordsFilter;
use SearchEngine\Transformer\SymbolTransformer;

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

        $this->engine = new SearchEngine($storage, $schema, $tokenizer);

        if (!$storage->exists()) {
            $storage->truncate();
            $handler = fopen(__DIR__ . '/pg11.txt', 'r+');

            $chapter = 'unknown';
            $line = 0;
            while (!feof($handler)) {
                $line++;
                if ($line % 100 == 0) {
                    $t = microtime(true);
                    $this->engine->flush();
                    $this->assertLessThan(5., microtime(true) - $t, 'more than 5 seconds to write on ' . $line);
                }

                $text = trim(fgets($handler));
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
        //fwrite(STDERR, print_r($diff, TRUE));
        $this->assertCount($matches, $results);
    }

    public static function provideSearches(): array
    {
        return [
            ['Mabel', 0.05, 4],
            ['Alice', 0.3, 400],
            ['said poor Alice', 1., 1],
            ['Alice NOT(wonderland)', 0.3, 395],
        ];
    }
}
