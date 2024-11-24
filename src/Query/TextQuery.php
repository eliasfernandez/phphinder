<?php

namespace SearchEngine\Query;

interface TextQuery
{
    public function getField(): string;
    public function getValue(): string;
    public function getBoost(): float;
}
