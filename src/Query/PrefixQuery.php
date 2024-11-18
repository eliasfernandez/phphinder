<?php

namespace SearchEngine\Query;

final class PrefixQuery extends Query
{
    public function __construct(
        private readonly string $field,
        private readonly string $prefix,
        protected float         $boost = 1.0,
    ) {
        parent::__construct([], $boost);
    }

    public function toString(): string
    {
        return "{$this->field}:{$this->prefix}*";
    }

    public function normalize(): self
    {
        return $this;
    }
}
