<?php

namespace PHPhinder\Index;

use PHPhinder\Exception\StorageException;
use PHPhinder\Schema\DefaultSchema;
use PHPhinder\Schema\Schema;
use PHPhinder\Token\RegexTokenizer;
use PHPhinder\Token\Tokenizer;
use PHPhinder\Utils\TypoTolerance;
use Toflar\StateSetIndex\Alphabet\Utf8Alphabet;
use Toflar\StateSetIndex\Config;
use Toflar\StateSetIndex\DataStore\InMemoryDataStore;
use Toflar\StateSetIndex\Levenshtein;
use Toflar\StateSetIndex\StateSetIndex;

abstract class AbstractStorage implements Storage
{
    public const KEY = 'k';
    public const ID = 'id';
    public const STATE = 's';
    protected Index $docs;
    /** @var array<string, Index> */
    protected array $indices;

    protected Index $state;

    /** @var array<string, mixed> $schemaVariables*/
    protected array $schemaVariables;

    protected StateSetIndex $stateSetIndex;

    public function __construct(
        protected readonly Schema $schema = new DefaultSchema(),
        protected readonly Tokenizer $tokenizer = new RegexTokenizer(),
    ) {
        $this->schemaVariables = (new \ReflectionClass($schema::class))->getDefaultProperties();
        $this->stateSetIndex = new StateSetIndex(
            new Config(TypoTolerance::INDEX_LENGTH, TypoTolerance::ALPHABET_SIZE),
            new Utf8Alphabet(),
            new StateSet($this),
            new InMemoryDataStore()
        );
    }

    public function truncate(): void
    {
        if ($this->docs->isCreated()) {
            $this->docs->drop();
        }

        if ($this->state->isCreated()) {
            $this->state->drop();
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

        /** @var StateSet $stateSet */
        $stateSet = $this->stateSetIndex->getStateSet();
        $stateSet->persist();

        $this->state->close();
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
        $this->save($this->docs, [self::ID => $docId], $doc);
    }

    abstract public function saveStates(array $new, array $deleted): void;

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
                    if (~$options & Schema::IS_UNIQUE) {
                        $token = $this->transform($token);
                        if (null === $token) {
                            continue;
                        }
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

                    $this->save($this->indices[$name], [self::KEY => $token], $indexDoc);
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

    public function loadIndexWithTypoTolerance(string $name, int|float|bool|string $term): array
    {
        $ids = [];
        $levenshteinDistance = TypoTolerance::getLevenshteinDistanceForTerm($term);

        if ($levenshteinDistance === 0) {
            return [];
        }

        $states = $this->stateSetIndex->findMatchingStates($term, $levenshteinDistance, 1);

        foreach ($this->loadByStates($this->indices[$name], $states) as $doc) {
            if (Levenshtein::distance($term, $doc['k']) > $levenshteinDistance) {
                continue;
            }
            $ids = array_merge($ids, explode(',', $doc['ids']));
        }

        return $ids;
    }

    /**
     * @param array<string> $docIds
     */
    protected function saveIndex(Index $index, string $term, array $docIds): void
    {
        $state = null;

        if (Schema::IS_UNIQUE & ~$index->getSchemaOptions()) {
            $state = current($this->stateSetIndex->index([$term]));
            /** @var StateSet $stateSet */
            $stateSet = $this->stateSetIndex->getStateSet();
            $stateSet->add($state);
        }

        $this->save(
            $index,
            [self::KEY => $term],
            array_filter(
                [self::KEY => $term, 'ids' => implode(',', $docIds), self::STATE => $state],
                fn ($value) => !is_null($value)
            ),
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
    protected function loadIndices(string $term, bool $typoTolerance = false): array
    {
        $indexedVariables = array_filter($this->schemaVariables, fn(int $options) => boolval($options & Schema::IS_INDEXED));
        foreach ($indexedVariables as $name => $_) {
            if ($this->indices[$name]->getSchemaOptions() & Schema::IS_UNIQUE) {
                continue;
            }
            $indices[$name] = !$typoTolerance ? $this->loadIndex($name, $term) : $this->loadIndexWithTypoTolerance($name, $term);
        }

        return $indices;
    }

    /**
     * @return array<string, array<string>>
     */
    protected function loadIndicesWithTypoTolerance(string $term): array
    {
        return $this->loadIndices($term, true);
    }

    /**
     * @return array<string, array<string>>
     */
    protected function loadPrefixIndices(string $prefix): array
    {
        $indices = [];

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

    /**
     * @inheritdoc
     */
    public function getStates(): \Generator
    {
        foreach ($this->loadAll($this->state) as $state) {
            yield $state[self::STATE];
        }
    }

    /**
     * @inheritdoc
     */
    public function findDocIdsByIndex(string $term, ?string $index = null): array
    {
        $term = $this->transform($term);
        if (null === $term) {
            return [];
        }

        $this->open();
        $indices = $index ? [$index => $this->loadIndex($index, $term)] : $this->loadIndices($term);
        $this->commit();
        return $indices;
    }

    /**
     * @inheritdoc
     */
    public function findDocIdsByIndexWithTypoTolerance(string $term, ?string $index = null): array
    {
        $term = $this->transform($term);
        if (null === $term) {
            return [];
        }

        $this->open();
        $indices = $index ? [$index => $this->loadIndexWithTypoTolerance($index, $term)] : $this->loadIndicesWithTypoTolerance($term);
        $this->commit();
        return $indices;
    }

    /**
     * @return array<string, array<string>>
     */
    public function findDocIdsByPrefix(string $prefix, ?string $index = null): array
    {
        $prefix = $this->transform($prefix);
        if (null === $prefix) {
            return [];
        }

        $this->open();
        $indices = $index ? [$prefix => $this->loadPrefixIndex($index, $prefix)] : $this->loadPrefixIndices($prefix);
        $this->commit();

        return $indices;
    }

    /**
     * @param array{string: string} $search
     */
    abstract protected function loadPrefix(Index $index, array $search): \Generator;

    abstract public function initialize(): void;

    abstract public function open(array $opts = []): void;

    abstract public function count(): int;


    /**
     * @param FileIndex $index
     * @param array<int> $states
     */
    abstract protected function loadByStates(Index $index, array $states): \Generator;

    /**
     * @param array{string: string} $search
     * @return array<string, int|float|bool|string>
     */
    abstract protected function load(Index $index, array $search): array;

    /**
     * @return array<string, int|float|bool|string>
     */
    abstract protected function loadAll(Index $index): \Generator;


    /**
     * @param array{string: int|float|bool|string} $search
     * @param array<string, int|float|bool|string> $data
     */
    abstract protected function save(Index $index, array $search, array $data, ?callable $hitCallback = null): void;

     /**
     * @param array{string: string} $search
     */
    abstract protected function remove(Index $index, array $search): void;
}
