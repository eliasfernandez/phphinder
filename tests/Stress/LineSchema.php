<?php

namespace Tests\Stress;

use PHPhinder\Schema\Schema;
use PHPhinder\Schema\SchemaTrait;

class LineSchema implements Schema
{
    use SchemaTrait;

    public int $chapter =  Schema::IS_INDEXED | Schema::IS_STORED | Schema::IS_FULLTEXT;
    public int $text =  Schema::IS_INDEXED | Schema::IS_STORED | Schema::IS_FULLTEXT;
    public int $line = Schema::IS_INDEXED;
}
