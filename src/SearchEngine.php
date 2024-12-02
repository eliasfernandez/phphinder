<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
use SearchEngine\Utils\IDEncoder;

class SearchEngine
{
    private const string ANY_SYMBOL = '*';
    /**
     * @var array<Result>
     */
    private array $documents = [];

    /** @var array<string, int>  */
    private array $schemaVariables;

    public function __construct(
        private readonly Storage $storage,
        private readonly Schema $schema,
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
        $this->documents[$id] = new Result($id, $data);

        return $this;
    }

    public function flush(): void
    {
        $this->storage->open();
        foreach ($this->documents as $docId => $result) {
            $this->storage->saveDocument($docId, $result->getDocument());
            $this->storage->saveIndices($docId, $result->getDocument());
        }
        $this->storage->commit();
        $this->documents = [];
    }

    /**
     * @return array<string, array<string>>
     */
    public function findDocsByIndex(string $term): array
    {
        return $this->storage->findDocIdsByIndex($term);
    }

    /**
     * @param string $phrase
     * @return array<Result>
     */
    public function search(string $phrase): array
    {
        $parser = new QueryParser(self::ANY_SYMBOL);
        $query = $parser->parse($phrase);
        return $this->computeQuery($query, [], $phrase);
    }

    /**
     * @param array<Query> $subqueries
     * @param array<Result> $docs
     * @return array<Result>
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
     * @param array<Result> $docs
     * @return array<Result>
     */
    private function searchOr(array $subqueries, array $docs, string $phrase): array
    {
        $subqueries = $this->sortQueries($subqueries);
        foreach ($subqueries as $query) {
            $docs = $this->computeQuery($query, $docs, $phrase);
        }
        $docs = $this->weight($docs, $subqueries);
        return $this->sort($docs);
    }

    /**
     * @param array<Result> $docs
     * @return array<Result>
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
     * @param array<Result> $docs
     * @return array<Result>
     */
    private function searchTerm(TermQuery $query, array $docs): array
    {
        $termByIndex = [];

        $termByIndex[$query->getValue()] = $this->storage->findDocIdsByIndex(
            $query->getValue(),
            self::ANY_SYMBOL !== $query->getField() ? $query->getField() : null
        );
        return $this->attachDocuments($termByIndex, $docs);
    }

    /**
     * @param array<Result> $docs
     * @return array<Result>
     */
    private function searchPrefix(PrefixQuery $query, array $docs): array
    {
        $termByIndex = [];

        $termByIndex[$query->getValue()] = $this->storage->findDocIdsByPrefix(
            $query->getValue(),
            self::ANY_SYMBOL !== $query->getField() ? $query->getField() : null
        );

        return $this->attachDocuments($termByIndex, $docs);
    }

    /**
     * @param array<Result> $docs
     * @return array<Result>
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
     * @param array<int|string, Result> $docs
     * @return array<int|string, Result>
     */
    private function filterInAndCondition(array $subqueries, array $docs): array
    {
        $textQueries = $this->filterTextQueries($subqueries);
        return array_filter(
            $docs,
            fn(Result $doc) => count($doc->getTerms()) === count($textQueries)
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
     * @param array<Result> $docs
     * @return array<Result>
     */
    private function assignFulltextMatch(array $docs, string $phrase): array
    {
        foreach ($this->schemaVariables as $name => $value) {
            if ($value & Schema::IS_FULLTEXT) {
                foreach ($docs as $key => $doc) {
                    if (!isset($doc->getDocument()[$name])) {
                        throw new \LogicException(
                            sprintf('Field `%s` is declared as fulltext but not stored.', $name)
                        );
                    }
                    $docs[$key]->setFulltext(str_contains($doc->getDocument()[$name], $phrase));
                }
            }
        }
        return $docs;
    }

    /**
     * @param array<Result> $docs
     * @param array<Query> $queries
     * @return array<Result>
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

        foreach ($docs as $doc) {
            $doc->setWeight($this->calculateScore($doc, $terms));
        }
        return $docs;
    }

    /**
     * @param array<Result> $docs
     * @return array<Result>
     */
    private function sort(array &$docs): array
    {
        usort($docs, fn (Result $a, Result $b) => $b->getWeight() <=> $a->getWeight());

        return $docs;
    }

    /**
     * @param array<string, array<array{term: string, boost: float}>> $terms
     */
    private function calculateScore(Result $result, array $terms): float
    {
        $score = 0.0;

        foreach ($result->getIndices() as $index) {
            if (isset($terms[$index])) {
                $score += $this->boostTermByIndex($terms[$index], $result->getTerms(), $score);
            } else if (isset($terms[self::ANY_SYMBOL])) {
                $score += $this->boostTermByIndex($terms[self::ANY_SYMBOL], $result->getTerms(), $score);
            }
        }

        if ($result->isFulltext()) {
            $score += 10.0;
        }

        $score += 2.0 * count($result->getTerms());

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
     * @param array<int|string, Result> $docs
     * @return array<int|string, Result>
     */
    private function attachDocuments(array $termByIndex, array $docs): array
    {
        foreach ($termByIndex as $term => $indices) {
            foreach ($indices as $index => $data) {
                foreach ($data as $key) {
                    if (!isset($docs[$key])) {
                        $docs[$key] = new Result($key);
                    }
                    $docs[$key]->addTerm($term)->addIndex($index);
                }
            }
        }

        foreach ($this->storage->getDocuments(array_keys($docs)) as [$key, $data]) {
            $docs[$key]->setDocument($data);
        }

        return $docs;
    }
}
