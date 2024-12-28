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

use PHPhinder\Exception\StorageException;
use PHPhinder\Schema\DefaultSchema;
use PHPhinder\Schema\Schema;
use PHPhinder\Token\RegexTokenizer;
use PHPhinder\Token\Tokenizer;
use PHPhinder\Utils\StringHelper;

class DbalStorage extends AbstractStorage implements Storage
{
    public function __construct(
        private readonly string $connectionString,
        protected readonly Schema $schema = new DefaultSchema(),
        protected readonly Tokenizer $tokenizer = new RegexTokenizer()
    ) {
        $this->docs = new DbalIndex($connectionString,  sprintf('%s_%s', StringHelper::getShortClass($schema::class), 'docs'));
        $this->schemaVariables = (new \ReflectionClass($schema::class))->getDefaultProperties();

        /**
         * @var string $name
         * @var int $options
         */
        foreach ($this->schemaVariables as $name => $options) {
            if ($name === self::ID) {
                throw new StorageException(sprintf('The schema provided contains a property with the reserved name %s', self::ID));
            }
            if ($options & Schema::IS_INDEXED) {
                $this->indices[$name] = new DbalIndex($this->connectionString, sprintf('%s_%s', StringHelper::getShortClass($schema::class), $name));
            }
        }
    }

    public function initialize(): void
    {
        if (!$this->docs->isCreated()) {
            $this->docs->create(array_merge([self::ID], array_keys(
                array_filter($this->schemaVariables, fn ($var) => $var & Schema::IS_STORED)
            )));
        }
        /** @var DbalIndex $index */
        foreach ($this->indices as $index) {
            if (!$index->isCreated()) {
                $index->create([self::KEY, 'ids']);
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
     * @inheritDoc
     * @param DbalIndex $index
     */
    protected function save(Index $index, array $search, array $data, callable $hitCallback): void
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
