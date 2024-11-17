<?php

namespace SearchEngine\Schema;

interface Schema
{
    public const IS_REQUIRED = 1;
    public const IS_STORED = 2;
    public const IS_INDEXED = 4;
    public const IS_FULLTEXT = 8;
    public const IS_INT = 16;

}
