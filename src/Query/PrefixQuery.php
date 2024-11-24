<?php

namespace SearchEngine\Query;

final class PrefixQuery extends Query implements TextQuery
{
    public function __construct(
        private readonly string $field,
        private readonly string $prefix,
        protected float $boost = 1.0,
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

    public function getField(): string
    {
        return $this->field;
    }

    public function getValue(): string
    {
        return $this->prefix;
    }

    public function getBoost(): float
    {
        return $this->boost;
    }
}
