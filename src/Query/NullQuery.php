<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SearchEngine\Query;

final class NullQuery extends Query
{
    public function __construct(
        private readonly string $errorMessage = '',
        protected float $boost = 1.0
    ) {
    }

    public function toString(): string
    {
        return "<null> {$this->errorMessage}";
    }
}
