<?php

/**
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Index\Dbal;

use Doctrine\DBAL\Connection;

class PostgreSQLUpsertQueryProvider implements UpsertQueryProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function getUpsertBatchQuery(string $tableName, array $columns, array $data): string
    {
        return sprintf(
            <<<SQL
            INSERT INTO %s (%s) 
                VALUES %s 
                ON CONFLICT (%s) 
                DO UPDATE SET %s
            SQL,
            $tableName,
            implode(', ', $columns),
            implode(', ', array_map(
                fn (array $row) => '(' . implode(
                    ', ',
                    array_map(fn ($item) => $this->connection->quote($item), $row)
                ) . ')',
                $data
            )),
            $columns[0],
            implode(
                ', ',
                array_map(fn (string $column) => sprintf(
                    '%s = excluded.%s',
                    $column,
                    $column
                ), $columns)
            ),
        );
    }
}
