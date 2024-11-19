<?php

namespace SearchEngine\Query;

final class NotQuery extends Query
{
    // We want NOT query to be executed at last
    protected int $priority = 1;

    public function __construct(
        Query $subquery,
        protected float $boost = 1.0
    ) {
        parent::__construct([$subquery], $this->boost);
    }

    public function toString(): string
    {
        return "NOT(" . $this->getSubquery()->toString() . ")";
    }

    public function normalize(): self
    {
        return new NotQuery($this->getSubquery()->normalize(), $this->boost);
    }

    public function getSubquery(): Query
    {
        return $this->subqueries[0];
    }
}
