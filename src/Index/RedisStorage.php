<?php

/**
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Index;

use Doctrine\DBAL\ArrayParameterType;
use PHPhinder\Exception\StorageException;
use PHPhinder\Schema\DefaultSchema;
use PHPhinder\Schema\Schema;
use PHPhinder\Token\RegexTokenizer;
use PHPhinder\Token\Tokenizer;
use PHPhinder\Utils\StringHelper;
use PHPhinder\Utils\TypoTolerance;
use Toflar\StateSetIndex\Levenshtein;

class RedisStorage extends AbstractStorage implements Storage
{
    /** @var RedisIndex  */
    protected Index $state;

    /** @var RedisIndex */
    protected Index $docs;

    /** @var array<string, RedisIndex> */
    protected array $indices = [];

    public function __construct(
        private readonly string $connectionString,
        Schema $schema = new DefaultSchema(),
        Tokenizer $tokenizer = new RegexTokenizer()
    ) {
        parent::__construct($schema, $tokenizer);

        $this->docs = new RedisIndex($connectionString, sprintf('%s:%s', StringHelper::getShortClass($schema::class), 'docs'), array_merge([self::ID], array_keys(
            array_filter($this->schemaVariables, fn ($var) => boolval($var & Schema::IS_STORED))
        )));
        $this->state = new RedisIndex($connectionString, sprintf('%s:%s', StringHelper::getShortClass($schema::class), 'states'), [self::STATE]);

        /**
         * @var string $name
         * @var int $options
         */
        foreach ($this->schemaVariables as $name => $options) {
            if ($name === self::ID) {
                throw new StorageException(sprintf('The schema provided contains a property with the reserved name %s', self::ID));
            }
            if ($options & Schema::IS_INDEXED) {
                $properties = [self::KEY, 'ids', self::STATE];
                if ($options & Schema::IS_UNIQUE) {
                    unset($properties[2]);
                }
                $this->indices[$name] = new RedisIndex(
                    $this->connectionString,
                    sprintf('%s:%s', StringHelper::getShortClass($schema::class), $name),
                    $properties,
                    $options
                );
            }
        }
    }

    public function initialize(): void
    {
        // ignore for redis
    }


    public function open(array $opts = []): void
    {
        foreach ($this->indices as $index) {
            $index->open();
        }
        $this->docs->open();
    }

    public function count(): int
    {
        return $this->docs->getTotal();
    }

    /**
     * @inheritdoc
     */
    public function saveStates(array $new, array $deleted): void
    {
        if (count($new) > 0) {
            $this->state->insertMultiple(
                array_map(fn($state) => [RedisStorage::STATE => $state], $new)
            );
        }

        if (count($deleted) > 0) {
            $this->state->deleteMultiple(
                array_map(fn($state) => [RedisStorage::STATE => $state], $deleted)
            );
        }
    }

    /**
     * @param RedisIndex $index
     * @return array<string, int|float|bool|string>
     */
    protected function load(Index $index, array $search): array
    {
        return $index->find($search);
    }

    /**
     * @param RedisIndex $index
     */
    protected function loadPrefix(Index $index, array $search): \Generator
    {
        $search[key($search)] = current($search) . '%';
        return $index->findAll($search);
    }

    /**
     * @param RedisIndex $index
     */
    protected function loadAll(Index $index): \Generator
    {
        return $index->findAll();
    }

    /**
     * @inheritDoc
     * @param RedisIndex $index
     */
    protected function loadByStates(Index $index, array $states): \Generator
    {
        $keys = [];
        foreach ($this->state->findAll([self::STATE => $states]) as $value) {
            unset($value[self::STATE]);
            if (0 === count($value)) {
                continue;
            }
            $keys = array_merge($keys, array_keys($value));
        }

        if (0 === count($keys)) {
            return [];
        }

        foreach ($keys as $key) {
            $indexTerm = $index->find([self::KEY => $key]);
            if ([] === $indexTerm) {
                continue;
            }
            yield $indexTerm;
        }
    }

    /**
     * @inheritDoc
     * @param RedisIndex $index
     */
    protected function save(Index $index, array $search, array $data, ?callable $hitCallback = null): void
    {
        if (in_array($index, $this->indices, true) && isset($data[self::STATE])) {
            $this->state->set([self::STATE => $data[self::STATE]], [$data[self::KEY] => true]);
            unset($data[self::STATE]);
        }
        $index->set($search, $data);
    }

    /**
     * @inheritDoc
     * @param RedisIndex $index
     */
    protected function remove(Index $index, array $search): void
    {
        $index->delete($search);
    }
}
