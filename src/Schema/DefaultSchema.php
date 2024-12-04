<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Schema;

class DefaultSchema implements Schema
{
    use SchemaTrait;

    public int $_id = Schema::IS_STORED;
    public int $title = Schema::IS_REQUIRED | Schema::IS_STORED | Schema::IS_INDEXED;
    public int $text =  Schema::IS_INDEXED | Schema::IS_FULLTEXT;
}
