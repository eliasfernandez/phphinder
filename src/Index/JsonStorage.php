<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Index;

use PHPhinder\Schema\DefaultSchema;
use PHPhinder\Schema\Schema;
use PHPhinder\Token\RegexTokenizer;
use PHPhinder\Transformer\Transformer;
use PHPhinder\Token\Tokenizer;
use PHPhinder\Utils\StringHelper;

class JsonStorage implements Storage
{
    private const INDEX_LINE_LENGTH_MIN = 12;
    private const DOCS_LINE_LENGTH = 256;
    private const KEY = 'k';
    private const ID = 'id';
    private FileIndex $docs;
    /** @var array<string, FileIndex> */
    private array $indices;

    /** @var array<string, int>  */
    private array $schemaVariables;

    public function __construct(
        string $path,
        private readonly Schema $schema = new DefaultSchema(),
        private ?Tokenizer $tokenizer = null,
        private readonly int $indexLineLength = self::INDEX_LINE_LENGTH_MIN,
        private readonly int $docsLineLength = self::DOCS_LINE_LENGTH
    ) {

        $this->docs = new FileIndex($path . DIRECTORY_SEPARATOR . sprintf('%s_docs.json', StringHelper::getShortClass($schema::class)), $this->docsLineLength);
        $this->schemaVariables = (new \ReflectionClass($schema::class))->getDefaultProperties();

        foreach ($this->schemaVariables as $name => $value) {
            if ($name === self::ID) {
                throw new \StorageException(sprintf('The schema provided contains a property with the reserved name %s', self::ID));
            }
            if ($value & Schema::IS_INDEXED) {
                $this->indices[$name] = new FileIndex($path . DIRECTORY_SEPARATOR . sprintf('%s_%s_index.json', StringHelper::getShortClass($schema::class), $name));
            }
        }

        if (null === $this->tokenizer) {
            $this->tokenizer = new RegexTokenizer();
        }
    }

    public function initialize(): void
    {
        if (!$this->docs->isCreated()) {
            file_put_contents($this->docs->getPath(), "");
        }
        /** @var FileIndex $index */
        foreach ($this->indices as $index) {
            if (!$index->isCreated()) {
                file_put_contents($index->getPath(), "");
            }
        }
    }

    public function truncate(): void
    {
        if ($this->docs->isCreated()) {
            unlink($this->docs->getPath());
        }
        foreach ($this->indices as $index) {
            if ($index->isCreated()) {
                unlink($index->getPath());
            }
        }

        $this->initialize();
    }

    public function open(array $opts = ['mode' => 'r+']): void
    {
        foreach ($this->indices as $index) {
            $index->open($opts);
            if (!$index->getLength()) {
                $index->calculateLength();
            }
        }
        $this->docs->open($opts);
    }

    public function commit(): void
    {
        foreach ($this->indices as $index) {
            $index->close();
        }
        $this->docs->close();
    }
    public function saveDocument(string $docId, array $data): void
    {
        $doc = ['id' => $docId];
        $handler = $this->docs->getHandler();

        if (!$handler) {
            throw new \StorageException('The document handler is not open. Open it with JsonStorage::open().');
        }
        foreach ($this->schemaVariables as $name => $value) {
            if ($value & Schema::IS_REQUIRED && !isset($data[$name])) {
                throw new \StorageException(sprintf(
                    'No `%s` key provided for doc %s',
                    $name,
                    json_encode($data, JSON_THROW_ON_ERROR)
                ));
            }
            if ($value & Schema::IS_STORED) {
                $doc[$name] = $data[$name];
            }
        }
        $this->save($this->docs, sprintf('{"%s":"%s"', self::ID, $docId), $doc, fn (&$data, $lineData) => true);
    }

    public function getDocuments(array $docIds): \Generator
    {
        $this->open(['mode' => 'r']);
        foreach ($docIds as $docId) {
            yield [$docId, $this->loadDocument($docId)];
        }
        $this->commit();
    }


    public function loadDocument(string $docId): array
    {
        $pattern = sprintf('{"%s":"%s"', self::ID, $docId);

        return $this->load(
            $this->docs,
            $pattern
        );
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
                        $this->saveIndex($this->indices[$name], $token, $existingDocIds);
                    }
                }
            }
        }
    }

    public function count(): int
    {
        $this->docs->open(['mode' => 'r']);
        $lines = $this->docs->getTotalLines();
        $this->docs->close();
        return $lines;
    }

    public function exists(): bool
    {
        return $this->docs->isCreated();
    }


    public function findDocIdsByIndex(string $term, ?string $index = null): array
    {
        $this->open(['mode' => 'r']);
        $indices = $index ? $this->loadIndex($index, $term) : $this->loadIndices($term);
        $this->commit();
        return $indices;
    }

    public function getSchemaVariables(): array
    {
        return $this->schemaVariables;
    }

    private function saveIndex(FileIndex $index, string $term, array $docIds): void
    {
        if (!$index->getHandler()) {
            throw new \StorageException('The index handler is not open. Open it with JsonStorage::open().');
        }

        $this->save(
            $index,
            sprintf('{"%s":"%s"', self::KEY, $term),
            [self::KEY => $term, 'ids' => implode(',', $docIds)],
            function (&$data, $lineData) {
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
        $pattern = sprintf('{"%s":"%s"', self::KEY, $term);
        $doc = $this->load($this->indices[$name], $pattern);
        return isset($doc['ids']) ? explode(',', $doc['ids']) : [];
    }

    private function loadPrefixIndices(string $prefix): array
    {
        $indices = [];

        $prefix = $this->transform($prefix);
        if (null === $prefix) {
            return $indices;
        }

        $indexedVariables = array_filter($this->schemaVariables, fn(string $var) => $var & Schema::IS_INDEXED);
        foreach ($indexedVariables as $name => $_) {
            $indices[$name] = $this->loadPrefixIndex($name, $prefix);
        }

        return $indices;
    }
    private function loadPrefixIndex(string $name, string $prefix): array
    {
        $pattern = sprintf('{"%s":"%s', self::KEY, $prefix);
        $ids = [];
        foreach ($this->loadPrefix($this->indices[$name], $pattern) as $doc) {
            $ids = array_merge($ids, explode(',', $doc['ids']));
        }
        return $ids;
    }

    /**
     * @return array<string, array<string, string[]>>
     */
    public function findDocIdsByPrefix(string $prefix, ?string $index = null): array
    {
        $this->open(['mode' => 'r']);
        $indices = $index ? $this->loadPrefixIndex($index, $prefix) : $this->loadPrefixIndices($prefix);
        $this->commit();
        return $indices;
    }

    private function load(FileIndex $index, string $pattern): array
    {
        $data = [];
        [$hit, $line] = $this->binarySearch(
            $index,
            $pattern
        );

        if ($index->getHandler() && $hit) {
            $index->moveTo($line * $index->getLength());
            $line = $index->getLine();
            $data = json_decode($line, true, JSON_THROW_ON_ERROR);
        }

        return $data;
    }

    private function loadPrefix(FileIndex $index, string $pattern): \Generator
    {
        $results = $this->binarySearchByPrefix(
            $index,
            $pattern
        );

        foreach ($results as $line) {
            yield json_decode($line, true, JSON_THROW_ON_ERROR);
        }
    }

    private function save(FileIndex $index, string $pattern, array $data, callable $hitCallback): void
    {
        if (!flock($index->getHandler(), LOCK_EX)) {
            throw new \StorageException('The handler is lock. Check if there is any other service writing on one of the files.');
        }

        // Perform a binary search to find if the term exists
        [$hit, $line] = $this->binarySearch($index, $pattern);
        $offset = $line * $index->getLength();
        $index->moveTo($offset);
        $lineData = $index->getLine();
        $remainingBytes = '';
        if ($hit) {
            $hitCallback($data, $lineData);
        } else {
            $index->moveTo($offset);
            $remainingBytes = stream_get_contents($index->getHandler());
        }

        $newLine = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($newLine) + 1 > $index->getLength()) {
            $offset = $this->recalculateIndexLength($newLine, $index, $line);
        }
        $index->moveTo($offset); // rewind

        $index->write(str_pad($newLine, $index->getLength() - 1) . PHP_EOL . $remainingBytes);
        flock($index->getHandler(), LOCK_UN);
    }

    /**
     * @return array{bool, int}
     */
    private function binarySearch(FileIndex $index, string $pattern): array
    {
        $low = 0;
        $high = $index->getTotalLines() - 1;
        while ($low <= $high) {
            // point to the middle
            $mid = (int)(($low + $high) / 2);
            $offset = ($mid) * $index->getLength();
            $index->moveTo($offset);
            $line = $index->getLine();

            // compare
            $comparison = strncmp($line, $pattern, strlen($pattern));
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

    private function binarySearchByPrefix(FileIndex $index, string $prefix): array
    {
        [$hit, $mid] = $this->binarySearch($index, $prefix);
        if ($hit) {
            return $this->collectMatches($index, $mid, $prefix);
        }
        return [];
    }

    private function collectMatches(FileIndex $index, int $start, string $prefix): array
    {
        $matches = [];
        $current = $start;
        while ($current >= 0) {
            $offset = $current * $index->getLength();
            $index->moveTo($offset);
            $line = $index->getLine();

            if (strncmp($line, $prefix, strlen($prefix)) === 0) {
                $matches[] = $line;
            } else {
                break;
            }
            $current--;
        }

        $current = $start + 1;
        while ($current < $index->getTotalLines()) {
            $offset = $current * $index->getLength();
            $index->moveTo($offset);
            $line = $index->getLine();

            if (strncmp($line, $prefix, strlen($prefix)) === 0) {
                $matches[] = $line;
            } else {
                break;
            }
            $current++;
        }
        return $matches;
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

    private function recalculateIndexLength(string $newLine, FileIndex $index, int $line): int
    {
        $newLength = $this->getNewLength(strlen($newLine) + 1);
        $index->moveTo(0);
        $fileLines = explode(PHP_EOL, stream_get_contents($index->getHandler()));
        $index->moveTo(0);
        foreach ($fileLines as $fileLine) {
            if ($fileLine === '') {
                continue;
            }

            $index->write(str_pad($fileLine, $newLength - 1) . PHP_EOL);
        }
        $index->setLength($newLength);
        return $line * $newLength;
    }

    private function getNewLength(int $length): int
    {
        $multiplier = 1 + ceil($length / self::INDEX_LINE_LENGTH_MIN);
        return intval(self::INDEX_LINE_LENGTH_MIN * $multiplier);
    }
}
