<?php

namespace SearchEngine\Query;

abstract class TextQuery extends Query
{
    public function __construct(
        protected readonly string $field,
        protected readonly string $value,
        protected float $boost = 1.0,
    ) {
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

    public function normalize(): self
    {
        return $this;
    }

    public function toString(): string
    {
        return "{$this->field}:{$this->value}";
    }
}
