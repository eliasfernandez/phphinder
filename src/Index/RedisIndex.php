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

use PHPhinder\Transformer\StopWordsFilter;
use Predis\Client;
use Predis\Command\Argument\Search\SearchArguments;

class RedisIndex implements Index
{
    private readonly string $key;

    /**
     * @param array<string> $properties
     */
    public function __construct(private readonly Client $client, private readonly string $pattern, private readonly array $properties, private readonly int $schemaOptions = 0)
    {
        $this->key = $properties[0];
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
            $search = [$this->key => $results];
        }

        if (null !== $search[RedisStorage::STATE]) {
            if ($this->key === RedisStorage::STATE) {
                foreach ($search[RedisStorage::STATE] as $value) {
                    yield [RedisStorage::STATE => intval(str_replace(sprintf('phphinder:%s:', $this->pattern), '', $value))];
                }
            } else {
                $key = key($search);
                $states = current($search);
                foreach ($states as $state) {
                    $command = [
                        'FT.SEARCH',
                        sprintf('%s.%s', str_replace(':', '.', $this->pattern), RedisStorage::FT_NS_STATE),
                        sprintf('@%s:[%s %s]', $key, $state, $state),
                        'NOCONTENT'
                    ];
                    $result = $this->client->executeRaw($command);
                    array_shift($result);
                    if (count($result) > 0) {
                        foreach ($result as $value) {
                            yield $this->parseResult($this->client->hgetall($value));
                        }
                    }
                }
            }
        } else {
            foreach ($search[$this->key] as $value) {
                yield $this->parseResult($this->client->hgetall($value));
            }
        }
    }

    private function parseResult(array $result): array
    {
        foreach ($result as $key => $value) {
            if ($key === RedisStorage::STATE) {
                $result[$key] = intval($value);
            }
        }

        return $result;
    }

    public function findPrefix(array $search): \Generator
    {
        $searchPattern = str_replace('%', '', current($search));
        $results = $this->client->keys(sprintf('phphinder:%s:%s*', $this->pattern, $searchPattern));

        foreach ($results as $value) {
            yield $this->client->hgetall($value);
        }
    }

    /**
     * Regarding https://redis.io/docs/latest/develop/interact/search-and-query/query/exact-match/
     * we can't use a phrase that starts with a stop word.
     *
     * @param array<string, string> $search
     */
    public function findContaining(array $search, array $fields = ['id']): \Generator
    {
        $key = key($search);
        $value = current($search);
        $command = [
            'FT.SEARCH',
            sprintf('%s.%s', str_replace(':docs', '', $this->pattern), RedisStorage::FT_NS_NAME),
            sprintf('@%s:("%s")', $key, $value),
            'NOCONTENT'
        ];
        $result = $this->client->executeRaw($command);

        array_shift($result);
        if (count($result) > 0) {
            foreach ($result as $item) {
                yield ['id' => str_replace(sprintf('phphinder:%s:', $this->pattern), '', $item)];
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
