<?php

namespace SearchEngine\Index;

use SearchEngine\Schema\Schema;
use SearchEngine\Transformer\Transformer;
use SearchEngine\Token\Tokenizer;
use SearchEngine\Utils\StringHelper;

class JsonStorage implements Storage
{
    private const int INDEX_LINE_LENGTH = 2048;
    private const int DOCS_LINE_LENGTH = 256;
    private const string KEY = 'k';
    private string $docsFile;
    /** @var <string, string> */
    private array $indexFiles;
    /** @var resource|false */
    private $docsHandler = false;
    /** @var array<string, resource|false> */
    private array $indexHandlers = [];

    /** @var array<string, int>  */
    private array $schemaVariables;

    public function __construct(
        string $path,
        private readonly Schema $schema,
        private readonly Tokenizer $tokenizer,
        private readonly int $indexLineLength = self::INDEX_LINE_LENGTH,
        private readonly int $docsLineLength = self::DOCS_LINE_LENGTH
    ) {
        $this->docsFile = $path . DIRECTORY_SEPARATOR . sprintf('%s_docs.json', StringHelper::getShortClass($schema::class));
        $this->schemaVariables = (new \ReflectionClass($schema::class))->getDefaultProperties();

        foreach ($this->schemaVariables as $name => $value) {
            if ($value & Schema::IS_INDEXED) {
                $this->indexFiles[$name] = $path . DIRECTORY_SEPARATOR . sprintf('%s_%s_index.json', StringHelper::getShortClass($schema::class), $name);
            }
        }
    }

    public function initialize(): void
    {
        if (!file_exists($this->docsFile)) {
            file_put_contents($this->docsFile, "");
        }
        foreach ($this->indexFiles as $indexFile) {
            if (!file_exists($indexFile)) {
                file_put_contents($indexFile, "");
            }
        }
    }

    public function truncate(): void
    {
        if (file_exists($this->docsFile)) {
            unlink($this->docsFile);
        }
        foreach ($this->indexFiles as $indexFile) {
            if (file_exists($indexFile)) {
                unlink($indexFile);
            }
        }

        $this->initialize();
    }

    public function open(array $opts = ['mode' => 'r+']): void
    {
        foreach ($this->indexFiles as $name => $indexFile) {
            $this->indexHandlers[$name] = fopen($indexFile, $opts['mode']);
        }
        $this->docsHandler = fopen($this->docsFile, $opts['mode']);
    }

    public function commit(): void
    {
        foreach ($this->indexHandlers as $handler) {
            fclose($handler);
        }
        $this->indexHandlers = [];
        fclose($this->docsHandler);
    }
    public function saveDocument(string $docId, array $data): void
    {
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

    public function getDocuments(array $docIds): array
    {
        $this->open(['mode' => 'r']);
        $docs = [];
        foreach ($docIds as $docId) {
            $docs[$docId] = ['document' => $this->loadDocument($docId)];
        }
        $this->commit();

        return $docs;
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
                $tokens = $this->tokenizer->apply($data[$name]);
                foreach ($tokens as $token) {
                    /** @var Transformer $transformer */
                    $token = $this->transform($token);
                    if (null === $token) {
                        continue;
                    }
                    $existingDocIds = $this->loadIndex($name, $token);
                    if (!in_array($docId, $existingDocIds)) {
                        $existingDocIds[] = $docId;
                        $this->saveIndex($this->indexHandlers[$name], $token, $existingDocIds);
                    }
                }
            }
        }
    }

    /**
     * @param resource $handler
     * @param string $term
     * @param string[] $docIds
     */
    private function saveIndex($handler, string $term, array $docIds): void
    {
        if (!$handler) {
            throw new \LogicException('The index handler is not open. Open it with JsonStorage::open().');
        }

        $this->save(
            $handler,
            sprintf('{"%s":"%s"', self::KEY, $term),
            [self::KEY => $term, 'ids' => implode(',', $docIds)],
            $this->indexLineLength,
            function (&$data, $lineData) use ($docIds) {
                $line = json_decode($lineData, 1, 512, JSON_THROW_ON_ERROR);
                $data['ids'] = implode(',', array_unique(array_merge(
                    explode(',', $line['ids']),
                    explode(',', $data['ids'])
                )));
            }
        );
    }

    private function loadIndices(string $term): array
    {
        $indices = [];

        $term = $this->transform($term);
        if (null === $term) {
            return $indices;
        }

        $indexedVariables = array_filter($this->schemaVariables, fn(string $var) => $var & Schema::IS_INDEXED);
        foreach ($indexedVariables as $name => $_) {
            $indices[$name] = $this->loadIndex($name, $term);
        }

        return $indices;
    }

    private function loadIndex(string $name, string $term): array
    {
        $handler = $this->indexHandlers[$name];
        $pattern = sprintf('{"%s":"%s"', self::KEY, $term);
        $doc = $this->load($handler, $pattern, $this->indexLineLength);
        return isset($doc['ids']) ? explode(',', $doc['ids']) : [];
    }

    public function findDocsByIndex(string $term, ?string $index = null): array
    {
        $this->open(['mode' => 'r']);
        $indices = $index ? $this->loadIndex($index, $term) : $this->loadIndices($term);
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
            $pattern,
            $lineLength
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
        [$hit, $line] = $this->binarySearch($handler, $pattern, $lineLength);
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
        fwrite(
            $handler,
            str_pad($newLine, $lineLength - 1) . PHP_EOL . $remainingBytes
        );
        flock($handler, LOCK_UN);
    }

    /**
     * @param resource $handler
     * @return array{bool, int?}
     */
    private function binarySearch($handler, string $pattern, int $lineLength): array
    {
        if ($handler) {
            $low = 0;
            $high = $this->lines($handler) - 1;
            while ($low <= $high) {
                // point to the middle
                $mid = (int)(($low + $high) / 2);
                $offset = ($mid) * $lineLength;
                fseek($handler, $offset);
                $line = fgets($handler);

                // compare
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

    private function lines($handle): int
    {
        $line = 0;
        fseek($handle, 0);
        while (!feof($handle) && fgets($handle) !== false) {
            $line++;
        }
        return $line;
    }

    private function transform(mixed $term): mixed
    {
        /** @var Transformer $transformer */
        foreach ($this->schema->getTransformers() as $transformer) {
            $term = $transformer->apply($term);
            if (null === $term) {
                break;
            }
        }
        return $term;
    }
}
