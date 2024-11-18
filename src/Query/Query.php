<?php

namespace SearchEngine\Query;

abstract class Query implements \Stringable {
    protected array $subqueries = [];

    protected string $joint;

    /**
     * @param array<Query> $subqueries
     * @param $boost
     */
    public function __construct(array $subqueries, protected float $boost = 1.0)
    {
        foreach ($subqueries as $subquery) {
            if (!($subquery instanceof Query)) {
                throw new \InvalidArgumentException("Invalid subquery");
            }
        }
        $this->subqueries = $subqueries;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        $strings = array_map(fn($subquery) => $subquery->toString(), $this->subqueries);
        return "(" . implode(" {$this->joint} ", $strings) . ")";
    }

    public function normalize(): self
    {
        $normalized = [];
        foreach ($this->subqueries as $subquery) {
            $normalized[] = $subquery->normalize();
        }
        return new static($normalized, $this->boost);
    }
}