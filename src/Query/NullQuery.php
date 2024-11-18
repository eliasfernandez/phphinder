<?php

namespace SearchEngine\Query;

final class NullQuery extends Query
{
    public function __construct(
        private readonly string $errorMessage = '',
        protected float         $boost = 1.0
    ) {
        parent::__construct([], $boost);
    }

    public function toString(): string
    {
        return "<null>{$this->errorMessage}";
    }
}