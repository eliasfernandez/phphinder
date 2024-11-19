<?php

namespace SearchEngine;

use SearchEngine\Index\Storage;
use SearchEngine\Query\AndQuery;
use SearchEngine\Query\OrQuery;
use SearchEngine\Query\PrefixQuery;
use SearchEngine\Query\Query;
use SearchEngine\Query\QueryParser;
use SearchEngine\Query\TermQuery;
use SearchEngine\Schema\Schema;
use SearchEngine\Token\Tokenizer;
use SearchEngine\Utils\ArrayHelper;

class SearchEngine
{
    private const ANY_SYMBOL = '*';
    /**
     * @var array<array{id:string}>
     */
    private array $documents = [];
    private array $schemaVariables = [];

    public function __construct(
        private readonly Storage $storage,
        private readonly Schema $schema,
        private readonly Tokenizer $tokenizer,
    ) {
        $this->schemaVariables = (new \ReflectionClass($schema::class))->getDefaultProperties();
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
    public function findDocsByIndex(string $term): array
    {
        return $this->storage->findDocsByIndex($term);
    }

    /**
     * @param string $phrase
     * @return array<array{id:string}>
     */
    public function search(string $phrase): array
    {
        $parser = new QueryParser(self::ANY_SYMBOL, $this->schema);
        $query = $parser->parse($phrase);

        return $this->computeQuery($query, $phrase, []);
    }

    /**
     * @param array<Query> $subqueries
     */
    private function searchAnd(array $subqueries, string $phrase, array $docs): array
    {
        foreach ($subqueries as $query) {
            $docs = $this->computeQuery($query, $phrase, $docs);
        }

        // only get documents with all the tokens
        $docs = array_filter(
            $docs,
            fn (array $doc) => count($doc['terms']) === count($subqueries)
        );

        foreach ($this->schemaVariables as $name => $value) {
            if ($value & Schema::IS_FULLTEXT) {
                foreach ($docs as $key => $doc) {
                    if (!isset($doc['document'][$name])) {
                        throw new \LogicException(
                            sprintf('Field `%s` is declared as fulltext but not stored.', $name)
                        );
                    }
                    $docs[$key]['fulltext'] = str_contains($doc['document'][$name], $phrase);
                }
            }
        }
        return $docs;
    }

    /**
     * @param array<Query> $subqueries
     */
    public function searchOr(array $subqueries, string $phrase, array $docs): array
    {
        foreach ($subqueries as $query) {
            $docs = $this->computeQuery($query, $phrase, $docs);
        }

        foreach ($this->schemaVariables as $name => $value) {
            if ($value & Schema::IS_FULLTEXT) {
                foreach ($docs as $key => $doc) {
                    if (!isset($doc['document'][$name])) {
                        throw new \LogicException(
                            sprintf('Field `%s` is declared as fulltext but not stored.', $name)
                        );
                    }
                    $docs[$key]['fulltext'] = str_contains($doc['document'][$name], $phrase);
                }
            }
        }
        return $docs;
    }

    public function searchTerm(Query $query, string $phrase, array $docs): array
    {
        $termByIndex = [];

        $termByIndex[$query->getValue()] = $this->storage->findDocsByIndex(
            $query->getValue(),
            self::ANY_SYMBOL !== $query->getField() ? $query->getField() : null
        );

        foreach ($termByIndex as $term => $indices) {
            foreach ($indices as $index => $data) {
                foreach ($data as $doc) {
                    if (!isset($docs[$doc])) {
                        $docs[strval($doc)] = [
                            'indices' => [$index],
                            'terms' => [],
                            'fulltext' => false,
                        ];
                    }
                    $docs[strval($doc)]['terms'][] = $term;
                }
            }
        }

        return ArrayHelper::arrayMergeRecursivePreserveKeys(
            $docs,
            $this->storage->getDocuments(array_keys($docs))
        );
    }

    public function searchPrefix(Query $query, string $phrase, array $docs): array
    {
        return $docs;
    }

    private function computeQuery(Query $query, string $phrase, array $docs): array
    {
        return match (true) {
            $query instanceof AndQuery => $this->searchAnd($query->getSubqueries(), $phrase, $docs),
            $query instanceof OrQuery => $this->searchOr($query->getSubqueries(), $phrase, $docs),
            $query instanceof TermQuery => $this->searchTerm($query, $phrase, $docs),
            $query instanceof PrefixQuery => $this->searchPrefix($query, $phrase, $docs),
            default => $docs
        };
    }
}
