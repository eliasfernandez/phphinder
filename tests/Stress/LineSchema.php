<?php

namespace Tests\Stress;

use SearchEngine\Schema\Schema;
use SearchEngine\Schema\SchemaTrait;

class LineSchema implements Schema
{
    use SchemaTrait;

    public int $chapter =  Schema::IS_INDEXED | Schema::IS_STORED | Schema::IS_FULLTEXT;
    public int $text =  Schema::IS_INDEXED | Schema::IS_STORED | Schema::IS_FULLTEXT;
    public int $line = Schema::IS_INDEXED;
}
