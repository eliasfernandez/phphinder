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

final class NotQuery extends GroupQuery
{
    // NOT query is the last executed
    protected int $priority = 1;

    public function __construct(
        Query $subquery,
        protected float $boost = 1.0
    ) {
        parent::__construct([$subquery], $this->boost);
    }

    public function toString(): string
    {
        return "NOT(" . $this->getSubquery()->toString() . ")";
    }

    public function normalize(): self
    {
        return new NotQuery($this->getSubquery()->normalize(), $this->boost);
    }

    public function getSubquery(): Query
    {
        return $this->subqueries[0];
    }
}
