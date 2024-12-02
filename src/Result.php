<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder;

class Result
{
    /** @var array<string>  */
    private array $indices = [];
    /** @var array<string>  */
    private array $terms = [];
    private bool $fulltext = false;
    private float $weight = 0;

    /**
     * @param array<string, string> $document
     */
    public function __construct(private array $document = [])
    {
    }

    /**
     * @return array<string>
     */
    public function getIndices(): array
    {
        return $this->indices;
    }

    public function addIndex(string $index): Result
    {
        if (!in_array($index, $this->indices)) {
            $this->indices[] = $index;
        }
        return $this;
    }

    /**
     * @return array<string>
     */
    public function getTerms(): array
    {
        return $this->terms;
    }

    public function addTerm(string $term): Result
    {
        if (!in_array($term, $this->terms)) {
            $this->terms[] = $term;
        }
        return $this;
    }

    public function isFulltext(): bool
    {
        return $this->fulltext;
    }

    public function setFulltext(bool $fulltext): Result
    {
        $this->fulltext = $fulltext;
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getDocument(): array
    {
        return $this->document;
    }

    /**
     * @param array<string, string> $document
     */
    public function setDocument(array $document): Result
    {
        $this->document = $document;
        return $this;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function setWeight(float $weight): Result
    {
        $this->weight = $weight;
        return $this;
    }
}
