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
     * @param array<string, string> $opts
     */
    public function open(array $opts = []): void;

    /**
     * Commits the changes and additions to the Storage
     */
    public function commit(): void;

    /**
     * Saves the document on the Storage.
     * @param array<string, int|float|bool|string> $data
     */
    public function saveDocument(string $docId, array $data): void;

    /**
     * Saves the indices on the Storage.
     * @param array<string, int|float|bool|string> $data
     */
    public function saveIndices(string $docId, array $data): void;

    /**
     * Saves states (if they don't exists) on the Storage, also
     * removes deleted states.
     * @param array<int> $new
     * @param array<int> $deleted
     */
    public function saveStates(array $new, array $deleted): void;

    /**
     * @return \Generator<int>
     */
    public function getStates(): \Generator;

    /**
     * @param array<int|string> $docIds
     * @return \Generator<array{string|int, array<string, int|float|bool|string>}>
     */
    public function getDocuments(array $docIds): \Generator;

    /**
     * @return array<string, int|float|bool|string>
     */
    public function loadDocument(string $docId): array;

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
     * Given a mistyped term, gets the doc ids by index in the form of an associative
     * array with this shape:
     *
     * [
     *     index1 => [ '1', '2', ... 'Z'],
     *     ...,
     *     indexN => [ '1', '2', ... 'Z'],
     * ]
     * @return array<string, array<string>>
     */
    public function findDocIdsByIndexWithTypoTolerance(string $term, ?string $index = null): array;

    /**
     * Given a prefix, gets the doc ids by index in the form of an associative array with
     * this shape:
     *
     * [
     *     index1 => [ '1', '2', ... 'Z'],
     *     ...,
     *     indexN => [ '1', '2', ... 'Z'],
     * ]
     *
     * @return array<string, array<string>>
     */
    public function findDocIdsByPrefix(string $prefix, ?string $index = null): array;

    /**
     * Given a text (including whitespaces), gets the doc ids by index in the form of an associative array with
     * this shape:
     *
     * [
     *     index1 => [ '1', '2', ... 'Z'],
     *     ...,
     *     indexN => [ '1', '2', ... 'Z'],
     * ]
     *
     * @return array<string, array<string>>
     */
    public function findDocIdsByFullText(string $text, ?string $index = null): array;


    /**
     * Looks for the document id on `index` for the search `term` :
     *
     * [ '1', '2', ... 'Z'],
     * @return array<string>
     */
    public function loadIndex(string $name, int|float|bool|string $term): array;

    /**
     * Count the total number of results.
     * (Requires the storage to be open))
     */
    public function count(): int;

    /**
     * @return array<string, int>
     */
    public function getSchemaVariables(): array;


    /**
     * Check if the document index exists.
     */
    public function exists(): bool;


    /**
     * Check if the document index is empty.
     */
    public function isEmpty(): bool;

    /**
     * @param array<string, int|float|bool|string> $doc
     * Remove doc relationships from indexed indices
     */
    public function removeDocFromIndices(array $doc): void;
}
