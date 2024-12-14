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

use PHPhinder\Transformer\Transformer;

interface Schema
{
    public const IS_REQUIRED = 1;
    public const IS_STORED = 2;
    public const IS_INDEXED = 4;
    public const IS_FULLTEXT = 8;
    public const IS_UNIQUE = 16;

    public function __construct(Transformer ...$transformers);

    /**
     * @return array<Transformer>
     */
    public function getTransformers(): array;
}
