<?php

namespace SearchEngine\Query;

final class PrefixQuery extends TextQuery
{
    public function toString(): string
    {
        return "{$this->field}:{$this->value}*";
    }
}
