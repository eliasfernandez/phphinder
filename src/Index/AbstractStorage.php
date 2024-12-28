<?php

namespace PHPhinder\Index;

use PHPhinder\Exception\StorageException;
use PHPhinder\Schema\Schema;
use PHPhinder\Token\Tokenizer;

abstract class AbstractStorage implements Storage
{
    public const KEY = 'k';
    public const ID = 'id';
    protected Index $docs;
    /** @var array<string, Index> */
    protected array $indices;

    /** @var array<string, mixed> $schemaVariables*/
    protected array $schemaVariables;
    protected readonly Schema $schema;
    protected readonly Tokenizer $tokenizer;


    public function truncate(): void
    {
        if ($this->docs->isCreated()) {
            $this->docs->drop();
        }
        foreach ($this->indices as $index) {
            if ($index->isCreated()) {
                $index->drop();
            }
        }

        $this->initialize();
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
        foreach ($this->schemaVariables as $name => $options) {
            if ($options & Schema::IS_REQUIRED && !isset($data[$name])) {
                throw new StorageException(sprintf(
                    'No `%s` key provided for doc %s',
                    $name,
                    json_encode($data, JSON_THROW_ON_ERROR)
                ));
            }
            if ($options & Schema::IS_STORED && isset($data[$name])) {
                $doc[$name] = $data[$name];
            }
        }
        $this->save($this->docs, [self::ID => $docId], $doc, fn (&$data, $lineData) => true);
    }

    public function loadDocument(string|int $docId): array
    {
        return $this->load(
            $this->docs,
            [self::ID => $docId],
        );
    }

    public function saveIndices(string $docId, array $data): void
    {
        foreach ($this->schemaVariables as $name => $options) {
            if ($options & Schema::IS_INDEXED) {
                $tokens = $this->tokenizer->apply($data[$name]);
                foreach ($tokens as $token) {
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


    public function exists(): bool
    {
        return $this->docs->isCreated();
    }

    public function isEmpty(): bool
    {
        return $this->docs->isEmpty();
    }

    public function getSchemaVariables(): array
    {
        return $this->schemaVariables;
    }

    /**
     * @param array<string, int|float|bool|string> $doc
     */
    public function removeDocFromIndices(array $doc): void
    {
        foreach ($this->schemaVariables as $name => $options) {
            if ($options & Schema::IS_INDEXED && isset($doc[$name]) && is_string($doc[$name])) {
                $tokens = $this->getTokensFor($doc[$name]);
                foreach ($tokens as $token) {
                     /** @var array{k: string, ids: string} $indexDoc */
                    $indexDoc = $this->removeDocumentFromToken($this->indices[$name], [self::KEY => $token], $doc['id']);
                    if ($indexDoc['ids'] === '') {
                        $this->remove($this->indices[$name], [self::KEY => $token]);
                        continue;
                    }

                    $this->save($this->indices[$name], [self::KEY => $token], $indexDoc, fn (&$data, $lineData) => true);
                }
            }
        }
    }

    public function loadIndex(string $name, int|float|bool|string $term): array
    {
         /** @var array{k: string, ids?: string} $doc */
        $doc = $this->load($this->indices[$name], [self::KEY => $term]);
        return isset($doc['ids']) ? explode(',', $doc['ids']) : [];
    }

    /**
     * @param array<string> $docIds
     */
    protected function saveIndex(Index $index, string $term, array $docIds): void
    {
        $this->save(
            $index,
            [self::KEY => $term],
            [self::KEY => $term, 'ids' => implode(',', $docIds)],
            function (&$data, $lineData) {
                /** @var array{k: string, ids: string} $line */
                $line = json_decode($lineData, true, 512, JSON_THROW_ON_ERROR);
                $data['ids'] = implode(',', array_unique(array_merge(
                    explode(',', $line['ids']),
                    explode(',', $data['ids'])
                )));
            }
        );
    }
        /**
     * @return array<string, array<string>>
     */
    protected function loadIndices(string $term): array
    {
        $indices = [];

        $term = $this->transform($term);
        if (null === $term) {
            return $indices;
        }

        $indexedVariables = array_filter($this->schemaVariables, fn(int $options) => boolval($options & Schema::IS_INDEXED));
        foreach ($indexedVariables as $name => $_) {
            $indices[$name] = $this->loadIndex($name, $term);
        }

        return $indices;

    }

    /**
     * @return array<string, array<string>>
     */
    protected function loadPrefixIndices(string $prefix): array
    {
        $indices = [];

        $prefix = $this->transform($prefix);
        if (null === $prefix) {
            return $indices;
        }

        $indexedVariables = array_filter(
            $this->schemaVariables,
            fn(int $options) => boolval($options & Schema::IS_INDEXED)
        );
        foreach ($indexedVariables as $name => $_) {
            $indices[$name] = $this->loadPrefixIndex($name, $prefix);
        }

        return $indices;
    }

    /**
     * @return array<string>
     */
    protected function loadPrefixIndex(string $name, string $prefix): array
    {
        $ids = [];
        /** @var array{k: string, ids: string} $doc */
        foreach ($this->loadPrefix($this->indices[$name], [self::KEY => $prefix]) as $doc) {
            $ids = array_merge($ids, explode(',', $doc['ids']));
        }
        return $ids;
    }


    protected function transform(string $term): ?string
    {
        foreach ($this->schema->getTransformers() as $transformer) {
            $term = $transformer->apply($term);
            if (null === $term) {
                break;
            }
        }
        return $term;
    }

    /**
     * @return array<string>
     */
    protected function getTokensFor(string $text): array
    {
        if (!$this->tokenizer) {
            throw new StorageException('There are no tokenizers defined.');
        }
        return array_unique(array_filter(array_map(
            fn(string $token) => $this->transform($token),
            $this->tokenizer->apply($text)
        )));
    }

    /**
     * @return array{k: string, ids: string}
     */
    protected function removeDocumentFromToken(Index $index, array $search, string $id): array
    {
        /** @var array{k: string, ids: string} $indexDoc */
        $indexDoc = $this->load($index, $search);

        $indexDoc['ids'] = implode(',', array_filter(
            explode(',', $indexDoc['ids']),
            fn($token) => $token !== $id
        ));
        return $indexDoc;
    }

    public function getDocuments(array $docIds): \Generator
    {
        $this->open();
        foreach ($docIds as $docId) {
            yield [$docId, $this->loadDocument($docId)];
        }
        $this->commit();
    }

    public function findDocIdsByIndex(string $term, ?string $index = null): array
    {
        $this->open();
        $indices = $index ? [$index => $this->loadIndex($index, $term)] : $this->loadIndices($term);
        $this->commit();
        return $indices;
    }

    /**
     * @return array<string, array<string>>
     */
    public function findDocIdsByPrefix(string $prefix, ?string $index = null): array
    {
        $this->open();
        $indices = $index ? [$prefix => $this->loadPrefixIndex($index, $prefix)] : $this->loadPrefixIndices($prefix);
        $this->commit();

        return $indices;
    }

    /**
     * @param array{string: string} $search
     */
    protected abstract function loadPrefix(Index $index, array $search): \Generator;

    public abstract function initialize(): void;

    public abstract function open(array $opts = []): void;

    public abstract function count(): int;

    /**
     * @param array{string: string} $search
     * @return array<string, int|float|bool|string>
     */
    protected abstract function load(Index $index, array $search): array;

    /**
     * @param array{string: string} $search
     * @param array<string, int|float|bool|string> $data
     */
    protected abstract function save(Index $index, array $search, array $data, callable $hitCallback): void;

     /**
     * @param array{string: string} $search
     */
    protected abstract function remove(Index $index, array $search): void;
}