<?php

namespace SearchEngine;

use SearchEngine\Index\Storage;

class SearchEngine
{
    /**
     * @var array<array{id:string}>
     */
    private array $documents = [];

    public function __construct(private Storage $storage)
    {
    }

    /**
     * @param array<string, string> $data
     */
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

    /**
     * @return array<string, array<string>>
     */
    public function search(string $term): array
    {
        return $this->storage->findIds($term);
    }

    /**
     * @return array{id:string}
     */
    public function getDocument(string $docId): array
    {
        return $this->storage->loadDocument($docId);
    }
}
