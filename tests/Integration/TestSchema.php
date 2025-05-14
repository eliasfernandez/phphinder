<?php

namespace Tests\Integration;

use PHPhinder\Schema\Schema;
use PHPhinder\Schema\SchemaTrait;

class TestSchema implements Schema
{
    use SchemaTrait;

    public int $_id = Schema::IS_INDEXED | Schema::IS_STORED | Schema::IS_UNIQUE;
    public int $title = Schema::IS_REQUIRED | Schema::IS_STORED | Schema::IS_INDEXED;
    public int $text =  Schema::IS_INDEXED | Schema::IS_STORED | Schema::IS_FULLTEXT;
    public int $description = Schema::IS_STORED;
}
