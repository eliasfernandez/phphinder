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

use Predis\Client;
use Predis\Command\Argument\Search\CreateArguments;
use Predis\Command\Argument\Search\SchemaFields\TextField;
use Predis\Command\Argument\Search\SearchArguments;

class RedisIndex implements Index
{
    private Client $client;

    private readonly string $key;


    private array $fulltextFields = [];

    /**
     * @param array<string> $properties
     */
    public function __construct(string $connectionString, private readonly string $pattern, private readonly array $properties, private readonly int $schemaOptions = 0)
    {
        $this->key = $properties[0];
        $this->client = new Client($connectionString);
    }

    public function addFulltextFields(array $fields): void
    {
        $this->fulltextFields = $fields;
    }

    public function open(): void
    {
        if (count($this->fulltextFields) === 0) {
            return;
        }

        try {
            $this->client->ftinfo('fulltext');
        } catch (\Exception $_) {
            $this->client->ftdropindex('fulltext');
            $this->client->ftcreate(
                'fulltext',
                array_map(fn (string $field) => new TextField($field), $this->fulltextFields),
                (new CreateArguments())->prefix([sprintf('phphinder:%s:', $this->pattern)])
            );
        }
    }

    public function close(): void
    {
    }

    public function isCreated(): bool
    {
        return !$this->isEmpty();
    }

    public function isEmpty(): bool
    {
        return $this->getTotal() === 0;
    }

    public function drop(): void
    {
        $this->client->del(sprintf('phphinder:%s', $this->pattern));
    }

    public function set(array $search, array $data): void
    {
        foreach ($data as $property => $value) {
            $this->client->hset(
                sprintf('phphinder:%s:%s', $this->pattern, $search[$this->key]),
                $property,
                $value
            );
        }
    }

    public function insertMultiple(array $items): void
    {
        if (0 === count($items)) {
            return;
        }

        foreach ($items as $item) {
            $this->set([$this->key => $item[$this->key]], $item);
        }
    }

    public function deleteMultiple(array $items): void
    {
        if (0 === count($items)) {
            return;
        }

        foreach ($items as $value) {
            $this->delete([$this->key => $value]);
        }
    }

    public function truncate(): void
    {
        $this->drop();
    }

    public function delete(array $search): void
    {
        $this->client->hdel(
            sprintf('phphinder:%s:%s', $this->pattern, current($search)),
            $this->properties
        );
    }

    public function find(array $search): array
    {
        return $this->client->hgetall(sprintf('phphinder:%s:%s', $this->pattern, current($search)));
    }

    public function findAll(array $search = null): \Generator
    {
        if (null === $search) {
            $results = $this->client->keys(sprintf('phphinder:%s:*', $this->pattern));
            $search = [$this->key => array_map(fn (string $pattern) =>
                str_replace(
                    sprintf('phphinder:%s:', $this->pattern),
                    '',
                    $pattern
                ), $results)];
        }

        foreach ($search[$this->key] as $value) {
            yield $this->client->hgetall(sprintf('phphinder:%s:%s', $this->pattern, $value));
        }
    }

    /**
     * @param array<string, string> $search
     */
    public function findContaining(array $search, array $fields = ['id']): \Generator
    {
        foreach ($search as $key => $value) {
            $result = $this->client->ftsearch(
                'fulltext',
                sprintf("@%s:(%s)", $key, $value),
                (new SearchArguments())->noContent()
            );

            if (count($result) > 0) {
                array_shift($result);
                foreach ($result as $item) {
                    yield ['id' => str_replace(sprintf('phphinder:%s:', $this->pattern), '', $item)];
                }
            }
        }
    }

    public function getTotal(): int
    {
        return count(
            $this->client->keys(sprintf('phphinder:%s:*', $this->pattern))
        );
    }

    public function getSchemaOptions(): int
    {
        return $this->schemaOptions;
    }
}
