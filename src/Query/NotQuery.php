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

final class NotQuery extends GroupQuery
{
    // NOT query is the last executed
    protected int $priority = 1;

    public function toString(): string
    {
        return "NOT(" . $this->getSubquery()->toString() . ")";
    }

    public function getSubquery(): Query
    {
        return $this->subqueries[0];
    }
}
