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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index as DoctrineIndex;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;

class DbalIndex implements Index
{
    /** @var Connection $handler */
    private $conn;

    public function __construct(string $connectionString, private readonly string $tableName, private readonly int $schemaOptions = 0)
    {
        $dsnParser = new DsnParser();
        $connectionParams = $dsnParser->parse($connectionString);

        $this->conn = DriverManager::getConnection($connectionParams);
    }

    public function open(): void
    {
    }

    public function close(): void
    {
    }

    /**
     * @return Connection
     */
    public function getConnection()
    {
        return $this->conn;
    }


    public function isCreated(): bool
    {
        $schemaManager = $this->conn->createSchemaManager();
        $tables = $schemaManager->listTableNames();

        return in_array($this->tableName, $tables);
    }

    public function isEmpty(): bool
    {
        return !$this->isCreated() || $this->getTotal() === 0;
    }

    public function create(array $columns): void
    {
        $schemaManager = $this->conn->createSchemaManager();

        $table = new Table(
            $this->tableName,
            array_map(
                fn (string $name) => new Column($name, match ($name) {
                    DbalStorage::ID, DbalStorage::KEY => new StringType(),
                    DbalStorage::STATE => new IntegerType(),
                    default => new TextType(),
                }),
                $columns
            ),
            array_filter([
                new DoctrineIndex(
                    'primary',
                    match (true) { /** @phpstan-ignore match.unhandled */
                        in_array(DbalStorage::KEY, $columns) => [DbalStorage::KEY],
                        in_array(DbalStorage::ID, $columns) => [DbalStorage::ID],
                        in_array(DbalStorage::STATE, $columns) => [DbalStorage::STATE],
                    },
                    true,
                    true
                ),
                in_array(DbalStorage::STATE, $columns) ? new DoctrineIndex(
                    sprintf('%s_state', $this->tableName),
                    [DbalStorage::STATE]
                ) : null,
            ])
        );
        $schemaManager->createTable($table);
    }

    public function drop(): void
    {
        $schemaManager = $this->conn->createSchemaManager();
        $schemaManager->dropTable($this->tableName);
    }

    public function upsert(array $search, array $data): void
    {
        $affectedRows = $this->conn->update($this->tableName, $data, $search);
        if (0 === $affectedRows) {
            try {
                $this->conn->insert($this->tableName, $data);
            } catch (Exception\UniqueConstraintViolationException $_) {
                $this->conn->update($this->tableName, $data, $search);
            }
        }
    }

    public function insertMultiple(array $columns, array $data): void
    {
        if (0 === count($data) || 0 === count($columns)) {
            return;
        }

        $this->conn->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $this->tableName,
                implode(', ', $columns),
                implode(', ', array_map(
                    fn (array $row) => '(' . implode(', ', $row) . ')',
                    $data
                ))
            )
        );
    }

    public function deleteMultiple(string $key, array $data): void
    {
        $this->conn->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE %s in %s',
                $this->tableName,
                $key,
                implode(', ', array_map(
                    fn (array $row) => '(' . implode(', ', $row) . ')',
                    $data
                ))
            )
        );
    }

    public function truncate(): void
    {
        $this->conn->executeStatement(
            sprintf('DELETE FROM %s', $this->tableName)
        );
    }

    public function delete(array $search): void
    {
        $this->conn->delete($this->tableName, $search);
    }

    public function find(array $search): array
    {
        $result = $this->conn->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ?', $this->tableName, key($search)),
            array_values($search)
        );
        if (false === $result || 0 === count($result)) {
            return [];
        }

        return $result;
    }

    public function findAll(array $search = null, $arrayParameterType = []): \Generator
    {
        if (null === $search) {
            $stmt = $this->conn->executeQuery(
                sprintf('SELECT * FROM %s', $this->tableName)
            );
        } else {
            $stmt = $this->conn->executeQuery(
                sprintf('SELECT * FROM %s WHERE %s IN (?)', $this->tableName, key($search)),
                array_values($search),
                $arrayParameterType
            );
        }

        while (($row = $stmt->fetchAssociative()) !== false) {
            yield $row;
        }
    }

    public function getTotal(): int
    {
        return intval($this->conn->fetchOne(sprintf('SELECT COUNT(id) FROM %s', $this->tableName)));
    }

    public function getSchemaOptions(): int
    {
        return $this->schemaOptions;
    }
}
