<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SearchEngine\Index;

interface Storage
{
    /**
     * Initialize the Storage
     */
    public function initialize(): void;

    /**
     * Truncate documents and indices on the Storage
     */
    public function truncate(): void;

    /**
     * Open the Storage
     */
    public function open(array $opts = []): void;

    /**
     * Commits the changes and additions to the Storage
     */
    public function commit(): void;

    /**
     * Saves the document on the Storage.
     */
    public function saveDocument(string $docId, array $data): void;

    /**
     * Saves the indices on the Storage.
     */
    public function saveIndices(string $docId, array $data): void;

    /**
     * @param array<int|string> $docIds
     * @return \Generator<string, array>
     */
    public function getDocuments(array $docIds): \Generator;

    /**
     * Given a term, gets the doc ids by index in the form of an associative array with
     * this shape:
     *
     * [
     *     index1 => [ '1', '2', ... 'Z'],
     *     ...,
     *     indexN => [ '1', '2', ... 'Z'],
     * ]
     * @return array<string, array<string>>
     */
    public function findDocIdsByIndex(string $term, ?string $index = null): array;

    /**
     * Given a prefix, gets the doc ids by index in the form of an associative array with
     * this shape:
     *
     * [
     *     index1 => [ '1', '2', ... 'Z'],
     *     ...,
     *     indexN => [ '1', '2', ... 'Z'],
     * ]
     * @return array<string, array<string>>
     */
    public function findDocIdsByPrefix(string $prefix, ?string $index = null): array;
    /**
     * Count the total number of results.
     */
    public function count(): int;
}
