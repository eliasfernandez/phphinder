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
use Predis\Client;
use Predis\Command\Argument\Search\CreateArguments;
use Predis\Command\Argument\Search\SchemaFields\NumericField;
use Predis\Command\Argument\Search\SchemaFields\TextField;
use Toflar\StateSetIndex\Levenshtein;

class RedisStorage extends AbstractStorage implements Storage
{
    public const FT_NS_NAME = 'fulltext';
    public const FT_NS_STATE = 'state';

    /** @var RedisIndex  */
    protected Index $state;

    /** @var RedisIndex */
    protected Index $docs;

    /** @var array<string, RedisIndex> */
    protected array $indices = [];

    private Client $client;

    public function __construct(
        readonly string $connectionString,
        Schema $schema = new DefaultSchema(),
        Tokenizer $tokenizer = new RegexTokenizer()
    ) {
        parent::__construct($schema, $tokenizer);

        $this->client = new Client($connectionString);

        $this->docs = new RedisIndex(
            $this->client,
            sprintf('%s:%s', StringHelper::getShortClass($schema::class), 'docs'),
            array_merge([self::ID], array_keys(
                array_filter($this->schemaVariables, fn ($var) => boolval($var & Schema::IS_STORED))
            ))
        );
        $this->state = new RedisIndex($this->client, sprintf('%s:%s', StringHelper::getShortClass($schema::class), 'states'), [self::STATE]);

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
                    $this->client,
                    sprintf('%s:%s', StringHelper::getShortClass($schema::class), $name),
                    $properties,
                    $options
                );
            }
        }
    }

    public function initialize(): void
    {
        $fulltextVariables = array_filter($this->schemaVariables, fn ($var) => $var & Schema::IS_STORED && $var & Schema::IS_FULLTEXT);
        if (count($fulltextVariables) > 0) {
            $this->generateFulltextIndex($fulltextVariables);
        }
        foreach ($this->schemaVariables as $name => $options) {
            $this->generateStateIndex($name);
        }
    }

    public function truncate(): void
    {
        try {
            $this->client->ftdropindex(sprintf('%s.%s', StringHelper::getShortClass($this->schema::class), self::FT_NS_NAME));

            foreach ($this->schemaVariables as $name => $options) {
                $this->client->ftdropindex(sprintf('%s.%s.%s', StringHelper::getShortClass($this->schema::class), $name, self::FT_NS_STATE));
            }

            $keys = $this->client->keys(sprintf('phphinder:%s:*', StringHelper::getShortClass($this->schema::class)));
            foreach ($keys as $key) {
                $this->client->del($key);
            }
        } catch (\Exception $_) {
        }

        parent::truncate();
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

    public function generateFulltextIndex(array $fulltextVariables): void
    {
        $redisIndexName = sprintf('%s.%s', StringHelper::getShortClass($this->schema::class), self::FT_NS_NAME);

        try {
            $this->client->ftinfo($redisIndexName);
        } catch (\Exception $_) {
            $this->client->ftcreate(
                $redisIndexName,
                array_map(fn(string $field) => new TextField($field), array_keys($fulltextVariables)),
                (new CreateArguments())->prefix(
                    [sprintf('phphinder:%s:%s:', StringHelper::getShortClass($this->schema::class), 'docs')]
                )->stopWords([])
            );
        }
    }

    public function generateStateIndex(string $name): void
    {
        $redisIndexName = sprintf('%s.%s.%s', StringHelper::getShortClass($this->schema::class), $name, self::FT_NS_STATE);

        try {
            $this->client->ftinfo($redisIndexName);
        } catch (\Exception $_) {
            $this->client->ftcreate(
                $redisIndexName,
                [new NumericField(self::STATE)],
                (new CreateArguments())->prefix(
                    [sprintf('phphinder:%s:%s:', StringHelper::getShortClass($this->schema::class), $name)]
                )->stopWords([])
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

        return $index->findPrefix($search);
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
        return $index->findAll([self::STATE => $states]);
    }

    /**
     * @inheritDoc
     * @param RedisIndex $index
     */
    protected function save(Index $index, array $search, array $data, ?callable $hitCallback = null): void
    {
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
