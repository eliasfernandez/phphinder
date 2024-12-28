<?php

/**
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Query;

final class PrefixQuery extends TextQuery
{
    public function toString(): string
    {
        return "{$this->field}:{$this->value}*";
    }
}
