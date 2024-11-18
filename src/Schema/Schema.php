<?php

namespace SearchEngine\Schema;

use SearchEngine\Transformer\Transformer;

interface Schema
{
    public const IS_REQUIRED = 1;
    public const IS_STORED = 2;
    public const IS_INDEXED = 4;
    public const IS_FULLTEXT = 8;
    public const IS_INT = 16;

    public function __construct(Transformer ...$transformers);
    public function getTransformers(): array;
}
