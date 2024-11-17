<?php

namespace SearchEngine\Index;

interface StorageInterface {

    public function initialize(): void;
    public function truncate(): void;
    public function open(): void;
    public function commit(): void;
    public function saveDocument(string $docId, array $data): void;
    public function loadDocument(string $docId): ?array;
    public function saveIndices(string $docId, array $data): void;
    public function loadIndex(string $term): array;
    public function search(string $term): array;
    public function count(): int;
}
