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
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;

class DbalIndex implements Index
{
    /** @var Connection $handler */
    private $conn;

    public function __construct(string $connectionString, private readonly string $tableName)
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
                fn (string $name) => new Column($name, in_array($name, [DbalStorage::ID, DbalStorage::KEY]) ? new StringType() : new TextType()),
                $columns
            ),
            [
                new DoctrineIndex(
                    'primary',
                    [in_array(DbalStorage::KEY, $columns) ? DbalStorage::KEY : DbalStorage::ID],
                    true,
                    true
                ),
            ]
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

    public function findAll(array $search): \Generator
    {
        $stmt = $this->conn->executeQuery(
            sprintf('SELECT * FROM %s WHERE %s = ?', $this->tableName, key($search)),
            array_values($search)
        );
        while (($row = $stmt->fetchAssociative()) !== false) {
            yield $row;
        }
    }

    public function getTotal(): int
    {
        return intval($this->conn->fetchOne(sprintf('SELECT COUNT(id) FROM %s', $this->tableName)));
    }
}
