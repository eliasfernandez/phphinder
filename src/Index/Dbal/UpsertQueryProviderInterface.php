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

interface UpsertQueryProviderInterface
{
    /**
     * Assumes the first column can only be the:
     * - `id`
     * - `k`
     * - `s`
     * Or, what is the same, the key on the table.
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $data
     */
    public function getUpsertBatchQuery(string $tableName, array $columns, array $data): string;
}
