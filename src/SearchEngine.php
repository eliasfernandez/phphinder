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
use SearchEngine\Utils\ArrayHelper;
use SearchEngine\Utils\IDEncoder;

class SearchEngine
{
    private const string ANY_SYMBOL = '*';
    /**
     * @var array<array{id: string, fulltext: bool, terms: array, indices: array}>
     */
    private array $documents = [];
    private array $schemaVariables = [];

    public function __construct(
        private readonly Storage $storage,
        private readonly Schema $schema,
    ) {
        $this->schemaVariables = (new \ReflectionClass($schema::class))->getDefaultProperties();
    }

    /**
     * @param array<string, string|int> $data
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
     * @return array<array{id:string, fulltext:bool, terms:array, indices:array}>
     */
    public function search(string $phrase): array
    {
        $parser = new QueryParser(self::ANY_SYMBOL);
        $query = $parser->parse($phrase);
        return $this->computeQuery($query, [], $phrase);
    }

    /**
     * @param array<Query> $subqueries
     * @param array<array{id: string, fulltext: bool, terms: array, indices: array}> $docs
     */
    private function searchAnd(array $subqueries, array $docs, string $phrase): array
    {
        $subqueries = $this->sortQueries($subqueries);
        foreach ($subqueries as $query) {
            $docs = $this->computeQuery($query, $docs, $phrase);
        }
        $docs = $this->filterInAndCondition($subqueries, $docs);
        $docs = $this->assignFulltextMatch($docs, $phrase);
        $docs = $this->weight($docs, $subqueries);
        return $this->sort($docs);
    }

    /**
     * @param array<Query> $subqueries
     * @param array<array{id: string, fulltext: bool, terms: array, indices: array}> $docs
     */
    private function searchOr(array $subqueries, array $docs, string $phrase): array
    {
        $subqueries = $this->sortQueries($subqueries);
        foreach ($subqueries as $query) {
            $docs = $this->computeQuery($query, $docs, $phrase);
        }

        $docs = $this->assignFulltextMatch($docs, $phrase);
        $docs = $this->weight($docs, $subqueries);
        $docs = $this->sort($docs);

        return $docs;
    }

    /**
     * @param array<array{id: string, fulltext: bool, terms: array, indices: array}> $docs
     */
    private function searchNot(Query $query, array $docs, string $phrase): array
    {
        $excludedDocs = $this->computeQuery($query, [], $phrase);
        $keys = array_keys($excludedDocs);
        foreach ($keys as $key) {
            if (isset($docs[$key])) {
                unset($docs[$key]);
            }
        }
        return $docs;
    }


    /**
     * @param array<array{id: string, fulltext: bool, terms: array, indices: array}> $docs
     */
    private function searchTerm(Query $query, array $docs): array
    {
        $termByIndex = [];

        $termByIndex[$query->getValue()] = $this->storage->findDocsByIndex(
            $query->getValue(),
            self::ANY_SYMBOL !== $query->getField() ? $query->getField() : null
        );

        return $this->attachDocuments($termByIndex, $docs);
    }

    /**
     * @param array<array{id: string, fulltext: bool, terms: array, indices: array}> $docs
     */
    private function searchPrefix(Query $query, array $docs): array
    {
        $termByIndex = [];

        $termByIndex[$query->getValue()] = $this->storage->findDocsByPrefix(
            $query->getValue(),
            self::ANY_SYMBOL !== $query->getField() ? $query->getField() : null
        );

        return $this->attachDocuments($termByIndex, $docs);
    }

    /**
     * @param array<array{id: string, fulltext: bool, terms: array, indices: array}> $docs
     * @return array<array{id:string, fulltext:bool, terms:array, indices:array}>
     */
    private function computeQuery(Query $query, array $docs, string $phrase): array
    {
        return match (true) {
            $query instanceof AndQuery => $this->searchAnd($query->getSubqueries(), $docs, $phrase),
            $query instanceof OrQuery => $this->searchOr($query->getSubqueries(), $docs, $phrase),
            $query instanceof TermQuery => $this->searchTerm($query, $docs),
            $query instanceof NotQuery => $this->searchNot($query->getSubquery(), $docs, $phrase),
            $query instanceof PrefixQuery => $this->searchPrefix($query, $docs),
            default => $docs
        };
    }

    /**
     * Assumes there must be the same amount of terms and text subqueries
     * @param array<Query> $subqueries
     */
    private function filterInAndCondition(array $subqueries, array $docs): array
    {
        $textQueries = $this->filterTextQueries($subqueries);
        return array_filter(
            $docs,
            fn(array $doc) => count($doc['terms']) === count($textQueries)
        );
    }

    /**
     * @param array<Query> $subqueries
     * @return array<Query>
     */
    private function sortQueries(array $subqueries): array
    {
        usort($subqueries, function (Query $a, Query $b) {
            return $a->getPriority() <=> $b->getPriority();
        });
        return $subqueries;
    }

    /**
     * @param array<array{id: string, fulltext: bool, terms: array, indices: array}> $docs
     * @return array<array{id:string, fulltext:bool, terms:array, indices:array}>
     */
    private function assignFulltextMatch(array $docs, string $phrase): array
    {
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
     * @param array<array{id: string, fulltext: bool, terms: array, indices: array}> $docs
     * @param array<Query> $queries
     * @return array<array{id:string, fulltext:bool, terms:array, indices:array}>
     */
    private function weight(array $docs, array $queries): array
    {
        $queries = $this->filterTextQueries($queries);

        $terms = [];
        foreach ($queries as $query) {
            if (!isset($terms[$query->getField()])) {
                $terms[$query->getField()] = [];
            }
            $terms[$query->getField()][] = ['term' => $query->getValue(), 'boost' => $query->getBoost()];
        }

        foreach ($docs as $key => $doc) {
            $docs[$key]['weight'] = $this->calculateScore($doc, $terms);
        }
        return $docs;
    }

    /**
     * @param array<array{id:string, fulltext: bool, terms: array, indices: array}> $docs
     * @return array<array{id: string, fulltext: bool, terms: array, indices: array, weight: float}>
     */
    private function sort(array &$docs): array
    {
        usort($docs, fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return $docs;
    }

    /**
     * @param array{id: string, fulltext: bool, terms: array, indices: array} $doc
     * @param array<string, array<array{term: string, boost: float}>> $terms
     */
    private function calculateScore(array $doc, array $terms): float
    {
        $score = 0.0;

        foreach ($doc['indices'] as $index) {
            if (isset($terms[$index])) {
                $score += $this->boostTermByIndex($terms[$index], $doc['terms'], $score);
            } else if (isset($terms[self::ANY_SYMBOL])) {
                $score += $this->boostTermByIndex($terms[self::ANY_SYMBOL], $doc['terms'], $score);
            }
        }

        if ($doc['fulltext']) {
            $score += 10.0;
        }

        $score += 2.0 * count($doc['terms']);

        return $score;
    }

    /**
     * @param array<Query> $subqueries
     * @return array<TextQuery>
     */
    private function filterTextQueries(array $subqueries): array
    {
        return array_filter($subqueries, fn($q) => $q instanceof TextQuery);
    }

    /**
     * @param array<array{term: string, boost: float}> $termsByIndex
     * @param string[] $terms
     */
    private function boostTermByIndex(array $termsByIndex, array $terms, float $score): float
    {
        $indexTerms = [];
        $boost = 0.0;
        foreach ($termsByIndex as $term) {
            $indexTerms [] = $term['term'];
            $boost += $term['boost'];
        }
        $termsInIndex = floatval(count(array_intersect($indexTerms, $terms)));
        if ($termsInIndex > 0) {
            $score += $termsInIndex * $boost / $termsInIndex;
        }
        return $score;
    }

    /**
     * @param array<string, array<string, string[]>> $termByIndex
     * @param array<int|string, array{indices: mixed, terms: mixed, fulltext: bool, document: mixed}> $docs
     * @return array<int|string, array{indices: mixed, terms: mixed, fulltext: bool, document: mixed}>
     */
    private function attachDocuments(array $termByIndex, array $docs): array
    {
        foreach ($termByIndex as $term => $indices) {
            foreach ($indices as $index => $data) {
                foreach ($data as $key) {
                    if (!isset($docs[$key])) {
                        $docs[$key] = [
                            'indices' => [$index],
                            'terms' => [],
                            'fulltext' => false,
                        ];
                    }
                    $docs[$key] = array_merge_recursive(
                        $docs[$key],
                        [
                            'indices' => !in_array($index, $docs[$key]['indices']) ? [$index] : [],
                            'terms' => [$term],
                        ]
                    );
                }
            }
        }
        return ArrayHelper::arrayMergeRecursivePreserveKeys(
            $docs,
            $this->storage->getDocuments(array_keys($docs))
        );
    }
}
