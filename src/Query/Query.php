<?php

namespace SearchEngine\Query;

abstract class Query implements \Stringable
{
    protected int $priority = 0;

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        return '<undefined>';
    }

    public function getPriority(): int
    {
        return $this->priority;
    }
}
