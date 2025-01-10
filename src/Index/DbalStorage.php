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

class DbalStorage extends AbstractStorage implements Storage
{
    /** @var DbalIndex  */
    protected Index $state;

    /** @var DbalIndex */
    protected Index $docs;

    /** @var array<string, DbalIndex> */
    protected array $indices = [];

    public function __construct(
        private readonly string $connectionString,
        Schema $schema = new DefaultSchema(),
        Tokenizer $tokenizer = new RegexTokenizer()
    ) {
        $this->docs = new DbalIndex($connectionString, sprintf('%s_%s', StringHelper::getShortClass($schema::class), 'docs'));
        $this->state = new DbalIndex($connectionString, sprintf('%s_%s', StringHelper::getShortClass($schema::class), 'states'));

        parent::__construct($schema, $tokenizer);

        /**
         * @var string $name
         * @var int $options
         */
        foreach ($this->schemaVariables as $name => $options) {
            if ($name === self::ID) {
                throw new StorageException(sprintf('The schema provided contains a property with the reserved name %s', self::ID));
            }
            if ($options & Schema::IS_INDEXED) {
                $this->indices[$name] = new DbalIndex(
                    $this->connectionString,
                    sprintf('%s_%s', StringHelper::getShortClass($schema::class), $name),
                    $options
                );
            }
        }
    }

    public function initialize(): void
    {
        if (!$this->docs->isCreated()) {
            $this->docs->create(array_merge([self::ID], array_keys(
                array_filter($this->schemaVariables, fn ($var) => boolval($var & Schema::IS_STORED))
            )));
        }

        if (!$this->state->isCreated()) {
            $this->state->create([self::STATE]);
        }

        /** @var DbalIndex $index */
        foreach ($this->indices as $index) {
            if (!$index->isCreated()) {
                $columns = [self::KEY, 'ids', self::STATE];
                if ($index->getSchemaOptions() & Schema::IS_UNIQUE) {
                    unset($columns[2]);
                }
                $index->create($columns);
            }
        }
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
                [DbalStorage::STATE],
                array_map(fn($state) => [$state], $new)
            );
        }

        if (count($deleted) > 0) {
            $this->state->deleteMultiple(
                DbalStorage::STATE,
                array_map(fn($state) => [$state], $deleted)
            );
        }
    }


    /**
     * @param DbalIndex $index
     * @return array<string, int|float|bool|string>
     */
    protected function load(Index $index, array $search): array
    {
        return $index->find($search);
    }

    /**
     * @param DbalIndex $index
     */
    protected function loadPrefix(Index $index, array $search): \Generator
    {
        $search[key($search)] = current($search) . '%';
        return $index->findAll($search);
    }

    /**
     * @param DbalIndex $index
     */
    protected function loadAll(Index $index): \Generator
    {
        return $index->findAll();
    }

    /**
     * @inheritDoc
     * @param DbalIndex $index
     */
    protected function loadByStates(Index $index, array $states): \Generator
    {
        return $index->findAll([self::STATE => $states], [ArrayParameterType::INTEGER]);
    }

    /**
     * @inheritDoc
     * @param DbalIndex $index
     */
    protected function save(Index $index, array $search, array $data, ?callable $hitCallback = null): void
    {
        $index->upsert($search, $data);
    }

    /**
     * @inheritDoc
     * @param DbalIndex $index
     */
    protected function remove(Index $index, array $search): void
    {
        $index->delete($search);
    }
}
