<?php

namespace SearchEngine;

use SearchEngine\Index\Storage;

class SearchEngine
{
    private array $documents = [];

    public function __construct(private Storage $storage)
    {
    }

    public function addDocument(array $data): self
    {
        $id = IDEncoder::encode(
            $this->storage->count() + count($this->documents) + 1
        );
        $this->documents[$id] = $data;
        return $this;
    }

    public function flush(): void
    {
        $this->storage->open();
        foreach ($this->documents as $docId => $data) {
            $this->storage->saveDocument($docId, $data);
            $this->storage->saveIndices($docId, $data);
        }
        $this->storage->commit();
        $this->documents = [];
    }

    public function search(string $term): array
    {
        return $this->storage->search($term);
    }

    public function getDocument(int $docId): array
    {
        return $this->storage->loadDocument($docId) ?? [];
    }
}
