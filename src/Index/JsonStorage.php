<?php

namespace SearchEngine\Index;

use SearchEngine\IDEncoder;
use SearchEngine\Schema\Schema;
use SearchEngine\Utils\StringHelper;

class JsonStorage implements StorageInterface {

    private const int INDEX_LINE_LENGTH = 2048;
    private const int DOCS_LINE_LENGTH = 256;
    private const string KEY = 'k';
    private string $docsFile;
    private string $indexFile;
    /** @var resource|false */
    private $docsHandler = false;
    /** @var resource|false */
    private $indexHandler = false;

    /** @var array<string, int>  */
    private array $schemaVariables=[];

    public function __construct(
        string $path,
        Schema $schema,
        private readonly int $indexLineLength = self::INDEX_LINE_LENGTH,
        private readonly int $docsLineLength = self::DOCS_LINE_LENGTH
    ){
        $this->docsFile = $path . DIRECTORY_SEPARATOR . sprintf('%s_docs.json', StringHelper::getShortClass($schema::class));
        $this->indexFile = $path . DIRECTORY_SEPARATOR . sprintf('%s_index.json',  StringHelper::getShortClass($schema::class));
        $this->schemaVariables = get_object_vars($schema);
    }

    public function initialize(): void
    {
        if (!file_exists($this->docsFile)) {
            file_put_contents($this->docsFile, "");
        }
        if (!file_exists($this->indexFile)) {
            file_put_contents($this->indexFile, "");
        }
    }

    public function truncate(): void
    {
        if (file_exists($this->docsFile)) {
            unlink($this->docsFile);
        }
        if (file_exists($this->indexFile)) {
            unlink($this->indexFile);
        }

        $this->initialize();
    }

    public function open(): void
    {
        $this->indexHandler = fopen($this->indexFile, 'r+');
        $this->docsHandler = fopen($this->docsFile, 'r+');
    }

    public function commit(): void
    {
        fclose($this->indexHandler);
        fclose($this->docsHandler);
    }
    public function saveDocument(string $docId, array $data): void {
        $doc = ['id' => $docId];
        $handler = $this->docsHandler;

        if (!$handler) {
            throw new \LogicException('The document handler is not open. Open it with JsonStorage::open().');
        }
        foreach ($this->schemaVariables as $name => $value) {
            if ($value & Schema::IS_REQUIRED && !isset($data[$name])) {
                throw new \LogicException(sprintf(
                    'No `%s` key provided for doc %s',
                    $name,
                    json_encode($data, JSON_THROW_ON_ERROR)
                ));
            }
            if ($value & Schema::IS_STORED) {
                $doc[$name] = $data[$name];
            }
        }
        $this->save($handler, sprintf('{"id":"%s"', $docId), $doc, $this->docsLineLength, fn (&$data, $lineData) => true);
    }

    public function loadDocument(string $docId): array
    {
        $handler = $this->docsHandler;
        $pattern = sprintf('{"id":"%s"', $docId);

        return $this->load($handler, $pattern, $this->docsLineLength);
    }

    public function saveIndices(string $docId, array $data): void
    {
        foreach ($this->schemaVariables as $name => $value) {
            if ($value & Schema::IS_INDEXED) {
                $tokens = $this->tokenize($data[$name]);
                foreach ($tokens as $token) {
                    $token = strtolower($token); // Normalize to lowercase
                    $existingDocIds = $this->loadIndex($token);
                    if (!in_array($docId, $existingDocIds)) {
                        $existingDocIds[] = $docId;
                        $this->saveIndex($token, $existingDocIds);
                    }
                }
            }
        }
    }

    // Save the index and maintain sorted order
    private function saveIndex(string $term, array $docIds): void {
        $handler = $this->indexHandler;

        if (!$handler) {
            throw new \LogicException('The index handler is not open. Open it with JsonStorage::open().');
        }

        $this->save($handler, sprintf('{"%s":"%s"', self::KEY, $term), [self::KEY => $term, 'ids' => implode(',',$docIds)], $this->indexLineLength, function (&$data, $lineData) {
            $line = json_decode($lineData, 1, 512, JSON_THROW_ON_ERROR);
            $data['ids'] = implode(',', array_unique(array_merge(
                explode(',', $line['ids']),
                explode(',', $data['ids'])
            )));
        });
    }

    // Load an index entry by term
    public function loadIndex(string $term): array
    {
        $handler = $this->indexHandler;
        $pattern = sprintf('{"%s":"%s"', self::KEY, $term);

        $index = $this->load($handler, $pattern, $this->indexLineLength);
        return isset($index['ids']) ? explode(',',$index['ids']) : [];
    }

    // Search docs by term
    public function search(string $term): array
    {
        $this->indexHandler = fopen($this->indexFile, 'r');
        $this->docsHandler = fopen($this->docsFile, 'r');
        $indices = $this->loadIndex($term);
        $this->commit();
        return $indices;
    }

    public function count(): int
    {
        $handler = fopen($this->docsFile, 'r');
        $lines = $this->lines($handler);
        fclose($handler);
        return $lines;
    }

    /**
     * @param resource|false $handler
     */
    private function load($handler, string $pattern, int $lineLength): array
    {
        $data = [];
        [$hit, $line] = $this->binarySearch(
            $handler,
            $pattern
        );
        if ($handler && $hit) {
            fseek($handler, $line * $lineLength);
            $line = fgets($handler);
            $data = json_decode($line, true, JSON_THROW_ON_ERROR);
        }
        return $data;
    }

    private function save($handler, string $pattern, array $data, int $lineLength, callable $hitCallback): void
    {
        if (!flock($handler, LOCK_EX)) {
            throw new \LogicException('The handler is lock. Check if there is any other service writing on one of the files.');
        }

        // Perform a binary search to find if the term exists
        [$hit, $line] = $this->binarySearch($handler, $pattern);
        $offset = $line * $lineLength;
        fseek($handler, $offset);
        $lineData = fgets($handler);
        $remainingBytes = '';
        if ($hit) {
            $hitCallback($data, $lineData);
        } else {
            fseek($handler, $offset);
            $remainingBytes = stream_get_contents($handler);
        }

        fseek($handler, $offset); // rewind
        $newLine = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        fwrite($handler,
            str_pad(
                $newLine,
                $lineLength - 1
            ) . PHP_EOL . $remainingBytes);
        flock($handler, LOCK_UN);
    }

    /**
     * @param resource $handler
     * @return array{bool, int?}
     */
    private function binarySearch($handler, string $pattern): array
    {
        if ($handler) {
            $low = 0;
            $high = $this->lines($handler) - 1;
            while ($low <= $high) {
                $mid = (int)(($low + $high) / 2);
                $offset = $mid * self::INDEX_LINE_LENGTH;
                fseek($handler, $offset);
                $line = fgets($handler);
                $comparison = strcmp(substr($line, 0, strlen($pattern)), $pattern);
                if ($comparison === 0) {
                    return [true, $mid]; // Term found
                } elseif ($comparison < 0) {
                    $low = $mid + 1; // Search from middle to bottom
                } else {
                    $high = $mid - 1; // Search from middle to top
                }
            }
            return [false, $low];
        }
        return [false, null];
    }

    private function lines($handle): int {
        $line = 0;
        fseek($handle, 0);
        while (!feof($handle) && fgets($handle) !== false) {
            $line++;
        }
        return $line;
    }

    private function tokenize(string $text): array {
        return preg_split('/\W+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }
}
