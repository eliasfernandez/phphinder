<?php

namespace SearchEngine\Query;

final class TermQuery extends Query implements TextQuery
{
    public function __construct(
        private readonly string $field,
        private readonly string $value,
        protected float $boost = 1.0,
    ) {
        parent::__construct([], $boost);
    }

    public function toString(): string
    {
        return "{$this->field}:{$this->value}";
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
        return $this->value;
    }

    public function getBoost(): float
    {
        return $this->boost;
    }
}
