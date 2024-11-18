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
     */
    public function loadDocument(string $docId): array;
    public function saveIndices(string $docId, array $data): void;

    /**
     * @return array<string, array<string>>
     */
    public function findIds(string $term): array;
    public function count(): int;
}
