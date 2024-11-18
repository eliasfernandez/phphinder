<?php

namespace SearchEngine\Query;

final class NotQuery extends Query
{
    public function __construct(
        protected Query $subquery,
        protected float $boost = 1.0
    ) {
        parent::__construct([], $this->boost);
    }

    public function toString(): string
    {
        return "NOT (" . $this->subquery->toString() . ")";
    }

    public function normalize(): self
    {
        return new NotQuery($this->subquery->normalize(), $this->boost);
    }
}
