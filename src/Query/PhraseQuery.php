<?php

namespace SearchEngine\Query;

final class PhraseQuery extends Query
{
    public function __construct(
        private string $fieldname,
        private array $words,
        private int $slop = 1,
        protected float $boost = 1.0,
        private ?array $charRanges = null,
    ) {
        parent::__construct([], $boost);
    }

    public function toString(): string
    {
        return "{$this->fieldname}:\"" . implode(' ', $this->words) . "\"";
    }

    public function equals($other): bool
    {
        return $other instanceof self &&
            $this->fieldname === $other->fieldname &&
            $this->words === $other->words &&
            $this->slop === $other->slop &&
            $this->boost === $other->boost;
    }

    public function hasTerms(): bool
    {
        return !empty($this->words);
    }

    public function terms(bool $includePhrases = false): array
    {
        if ($includePhrases) {
            return array_map(fn($word) => [$this->fieldname, $word], $this->words);
        }
        return [];
    }

    public function tokens(float $boost = 1.0): array
    {
        $tokens = [];
        $charRanges = $this->charRanges;

        foreach ($this->words as $i => $word) {
            $startChar = null;
            $endChar = null;

            if ($charRanges) {
                [$startChar, $endChar] = $charRanges[$i];
            }

            $tokens[] = new Token(
                $this->fieldname,
                $word,
                $boost * $this->boost,
                $startChar,
                $endChar
            );
        }

        return $tokens;
    }

    public function normalize(): self
    {
        if (empty($this->words)) {
            return new NullQuery(); // Assume NullQuery is defined elsewhere
        }

        if (count($this->words) === 1) {
            $term = new Term($this->fieldname, $this->words[0]);
            if ($this->charRanges) {
                [$term->startChar, $term->endChar] = $this->charRanges[0];
            }
            return $term;
        }

        $filteredWords = array_filter($this->words, fn($word) => $word !== null);
        return new self(
            $this->fieldname,
            $filteredWords,
            $this->slop,
            $this->boost,
            $this->charRanges
        );
    }

    public function replace(string $fieldname, string $oldText, string $newText): self
    {
        $clone = clone $this;
        if ($clone->fieldname === $fieldname) {
            foreach ($clone->words as &$word) {
                if ($word === $oldText) {
                    $word = $newText;
                }
            }
        }
        return $clone;
    }

    private function createAndQuery(): AndQuery
    {
        $terms = array_map(fn($word) => new Term($this->fieldname, $word), $this->words);
        return new AndQuery($terms); // Assume AndQuery is defined elsewhere
    }

    public function estimateSize(IndexReader $reader): int
    {
        return $this->createAndQuery()->estimateSize($reader);
    }

    public function estimateMinSize(IndexReader $reader): int
    {
        return $this->createAndQuery()->estimateMinSize($reader);
    }

    public function matcher(Searcher $searcher, ?Context $context = null): Matcher
    {
        $schema = $searcher->getSchema();

        if (!isset($schema[$this->fieldname])) {
            return new NullMatcher(); // Assume NullMatcher is defined elsewhere
        }

        $field = $schema[$this->fieldname];
        if (!$field->supportsPositions()) {
            throw new QueryError("Field '{$this->fieldname}' does not support positions.");
        }

        $terms = [];
        $reader = $searcher->getReader();

        foreach ($this->words as $word) {
            $encodedWord = $field->encode($word);

            if (!$reader->termExists($this->fieldname, $encodedWord)) {
                return new NullMatcher();
            }

            $terms[] = new Term($this->fieldname, $encodedWord);
        }

        $spanNearQuery = new SpanNearQuery($terms, $this->slop, true, 1); // Assume SpanNearQuery is defined
        $matcher = $spanNearQuery->matcher($searcher, $context);

        if ($this->boost !== 1.0) {
            $matcher = new BoostingMatcher($matcher, $this->boost); // Assume BoostingMatcher is defined
        }

        return $matcher;
    }
}
