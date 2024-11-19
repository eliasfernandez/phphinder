<?php

namespace SearchEngine\Index;

interface Storage
{
    public function initialize(): void;
    public function truncate(): void;
    public function open(array $opts = []): void;
    public function commit(): void;
    public function saveDocument(string $docId, array $data): void;

    /**
     * @return array{id: string, ...<string, string>}
     * @param array<string> $docIds
     */
    public function getDocuments(array $docIds): array;
    public function saveIndices(string $docId, array $data): void;

    /**
     * @return array<string, array<string>>
     */
    public function findDocsByIndex(string $term, ?string $index = null): array;
    public function count(): int;
}
