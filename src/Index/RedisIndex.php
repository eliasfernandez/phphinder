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

class RedisIndex implements Index
{
    private Client $client;

    private readonly string $key;

    /**
     * @param array<string> $properties
     */
    public function __construct(string $connectionString, private readonly string $pattern, private readonly array $properties, private readonly int $schemaOptions = 0)
    {
        $this->key = $properties[0];
        $this->client = new Client($connectionString);
    }

    public function open(): void
    {
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
