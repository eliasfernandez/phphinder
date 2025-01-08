<?php

/**
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder;

use PHPhinder\Exception\StorageException;
use PHPhinder\Index\Storage;
use PHPhinder\Query\AndQuery;
use PHPhinder\Query\NotQuery;
use PHPhinder\Query\OrQuery;
use PHPhinder\Query\PrefixQuery;
use PHPhinder\Query\Query;
use PHPhinder\Query\QueryParser;
use PHPhinder\Query\TermQuery;
use PHPhinder\Query\TextQuery;
use PHPhinder\Schema\Schema;
use PHPhinder\Utils\IDEncoder;

class SearchEngine
{
    private const ANY_SYMBOL = '*';
    /**
     * @var array<Result>
     */
    private array $results = [];

    public function __construct(private readonly Storage $storage)
    {
        if (!$this->storage->exists()) {
            $this->storage->initialize();
        }
    }

    /**
     * @param array<string, int|float|bool|string> $data
     */
    public function addDocument(array $data): self
    {
        $this->results[] = new Result($data);

        return $this;
    }

    public function flush(): void
    {
        $this->storage->open(['mode' => 'r+']);
        foreach ($this->results as $result) {
            $docId = $this->getId($result);
            $this->storage->saveDocument($docId, $result->getDocument());
            $this->storage->saveIndices($docId, $result->getDocument());
        }
        $this->storage->commit();
        $this->results = [];
    }

    /**
     * @param array<string, int|float|bool|string> $doc
     * @return array<string, int|float|bool|string>
     */
    private function getUniqueDocument(array $doc): array
    {
        foreach ($this->storage->getSchemaVariables() as $variable => $options) {
            if ($options & Schema::IS_UNIQUE && isset($doc[$variable])) {
                $docInIndex = $this->storage->loadIndex($variable, $doc[$variable]);
                $id = current($docInIndex);
                if ($id === false) {
                    continue;
                }
                return $this->storage->loadDocument($id);
            }
        }
        return[];
    }

    private function nextId(): string
    {
        return IDEncoder::encode($this->storage->count() + 1);
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

        /**
         * Only if there are no matches, use the typo tolerance search
         */
        if (
            array_sum(array_map(
                fn (array $indexIds) => count($indexIds),
                $termByIndex[$query->getValue()]
            )) === 0
        ) {
            $termByIndex[$query->getValue()] = $this->storage->findDocIdsByIndexWithTypoTolerance(
                $query->getValue(),
                self::ANY_SYMBOL !== $query->getField() ? $query->getField() : null
            );
        }
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
        foreach ($this->storage->getSchemaVariables() as $name => $options) {
            if ($options & Schema::IS_FULLTEXT) {
                foreach ($docs as $key => $doc) {
                    if (!isset($doc->getDocument()[$name])) {
                        throw new StorageException(
                            sprintf('Field `%s` is declared as fulltext but not stored.', $name)
                        );
                    }
                    if (!is_string($doc->getDocument()[$name])) {
                        continue;
                    }
                    $doc->setFulltext(str_contains($doc->getDocument()[$name], $phrase));
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
                        $docs[$key] = new Result();
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

    private function getId(Result $result): string
    {
        $oldDocument = $this->getUniqueDocument($result->getDocument());

        if ($oldDocument) {
            $this->storage->removeDocFromIndices($oldDocument);
            return (string) $oldDocument['id'];
        }

        return $this->nextId();
    }
}
