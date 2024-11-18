<?php

namespace SearchEngine\Query;

final class TermQuery extends Query
{
    public function __construct(
        private readonly string $field,
        private readonly string $value,
        protected float         $boost = 1.0,
    ) {
        parent::__construct([], $boost);
    }

    public function toString():string
    {
        return "{$this->field}:{$this->value}";
    }

    public function normalize(): self
    {
        return $this;
    }
}
