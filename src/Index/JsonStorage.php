<?php

namespace SearchEngine\Index;

use SearchEngine\Schema\Schema;
use SearchEngine\Transformer\Transformer;
use SearchEngine\Token\Tokenizer;
use SearchEngine\Utils\StringHelper;

class JsonStorage implements Storage
{
    private const int INDEX_LINE_LENGTH_MIN = 2048;
    private const int DOCS_LINE_LENGTH = 256;
    private const string KEY = 'k';
    private FileIndex $docs;
    /** @var array<string, FileIndex> */
    private array $indices;
    /** @var array<string, int>  */
    private array $schemaVariables;

    public function __construct(
        string $path,
        private readonly Schema $schema,
        private readonly Tokenizer $tokenizer,
        private readonly int $indexLineLength = self::INDEX_LINE_LENGTH_MIN,
        private readonly int $docsLineLength = self::DOCS_LINE_LENGTH
    ) {

        $this->docs = new FileIndex($path . DIRECTORY_SEPARATOR . sprintf('%s_docs.json', StringHelper::getShortClass($schema::class)), $this->docsLineLength);
        $this->schemaVariables = (new \ReflectionClass($schema::class))->getDefaultProperties();

        foreach ($this->schemaVariables as $name => $value) {
            if ($value & Schema::IS_INDEXED) {
                $this->indices[$name] = new FileIndex($path . DIRECTORY_SEPARATOR . sprintf('%s_%s_index.json', StringHelper::getShortClass($schema::class), $name), $this->indexLineLength);
            }
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
        $this->save($this->docs, sprintf('{"id":"%s"', $docId), $doc, fn (&$data, $lineData) => true);
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
        $pattern = sprintf('{"id":"%s"', $docId);

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

    private function saveIndex(FileIndex $index, string $term, array $docIds): void
    {
        if (!$index->getHandler()) {
            throw new \LogicException('The index handler is not open. Open it with JsonStorage::open().');
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

    public function findDocsByIndex(string $term, ?string $index = null): array
    {
        $this->open(['mode' => 'r']);
        $indices = $index ? $this->loadIndex($index, $term) : $this->loadIndices($term);
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

    private function save(FileIndex $index, string $pattern, array $data, callable $hitCallback): void
    {
        if (!flock($index->getHandler(), LOCK_EX)) {
            throw new \LogicException('The handler is lock. Check if there is any other service writing on one of the files.');
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

        $index->moveTo($offset); // rewind
        $newLine = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        fwrite(
            $index->getHandler(),
            str_pad($newLine, $index->getLength() - 1) . PHP_EOL . $remainingBytes
        );
        flock($index->getHandler(), LOCK_UN);
    }

    /**
     * @return array{bool, int?}
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
            $comparison = strcmp(substr($line, 0, strlen($pattern)), $pattern);
            if ($comparison === 0) {
                return [true, $mid]; // Term found
            } elseif ($comparison < 0) {
                $low = $mid + 1; // Search from middle to bottom
            } else {
                $high = $mid - 1; // Search from middle to top
            }
        }
        return [false, $low ?: null];
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
