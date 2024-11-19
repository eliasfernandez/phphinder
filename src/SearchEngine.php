<?php

namespace SearchEngine;

use SearchEngine\Index\Storage;
use SearchEngine\Query\AndQuery;
use SearchEngine\Query\NotQuery;
use SearchEngine\Query\OrQuery;
use SearchEngine\Query\PrefixQuery;
use SearchEngine\Query\Query;
use SearchEngine\Query\QueryParser;
use SearchEngine\Query\TermQuery;
use SearchEngine\Query\TextQuery;
use SearchEngine\Schema\Schema;
use SearchEngine\Token\Tokenizer;
use SearchEngine\Utils\ArrayHelper;
use SearchEngine\Utils\IDEncoder;

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
        $parser = new QueryParser(self::ANY_SYMBOL);
        $query = $parser->parse($phrase);
        return $this->computeQuery($query, $phrase, []);
    }

    /**
     * @param array<Query> $subqueries
     * @param array<array{id:string}> $docs
     */
    private function searchAnd(array $subqueries, string $phrase, array $docs): array
    {
        $subqueries = $this->sortQueries($subqueries);
        foreach ($subqueries as $query) {
            $docs = $this->computeQuery($query, $phrase, $docs);
        }
        $docs = $this->filterInAndCondition($subqueries, $docs);

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
     * @param array<array{id:string}> $docs
     */
    public function searchOr(array $subqueries, string $phrase, array $docs): array
    {
        $subqueries = $this->sortQueries($subqueries);
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

    /**
     * @param array<array{id:string}> $docs
     */
    public function searchNot(Query $query, string $phrase, array $docs): array
    {
        $excludedDocs = $this->computeQuery($query, $phrase, []);
        $keys = array_keys($excludedDocs);
        foreach ($keys as $key) {
            if (isset($docs[$key])) {
                unset($docs[$key]);
            }
        }
        return $docs;
    }


    /**
     * @param array<array{id:string}> $docs
     */
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

    /**
     * @param array<array{id:string}> $docs
     */
    public function searchPrefix(Query $query, string $phrase, array $docs): array
    {
        return $docs;
    }

    /**
     * @param Query $query
     * @param string $phrase
     * @param array<array{id:string}> $docs
     * @return array<array{id:string}>
     */
    private function computeQuery(Query $query, string $phrase, array $docs): array
    {
        return match (true) {
            $query instanceof AndQuery => $this->searchAnd($query->getSubqueries(), $phrase, $docs),
            $query instanceof OrQuery => $this->searchOr($query->getSubqueries(), $phrase, $docs),
            $query instanceof TermQuery => $this->searchTerm($query, $phrase, $docs),
            $query instanceof NotQuery => $this->searchNot($query->getSubquery(), $phrase, $docs),
            $query instanceof PrefixQuery => $this->searchPrefix($query, $phrase, $docs),
            default => $docs
        };
    }

    /**
     * Assumes there must be the same amount of terms and text subqueries
     * @param array<Query> $subqueries
     */
    private function filterInAndCondition(array $subqueries, array $docs): array
    {
        $textQueries = array_filter($subqueries, fn($q) => $q instanceof TextQuery);
        $docs = array_filter(
            $docs,
            fn(array $doc) => count($doc['terms']) === count($textQueries)
        );
        return $docs;
    }

    private function sortQueries(array $subqueries): array
    {
        usort($subqueries, function (Query $a, Query $b) {
            return $a->getPriority() <=> $b->getPriority();
        });
        return $subqueries;
    }
}
