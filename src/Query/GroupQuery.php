<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Query;

use PHPhinder\Exception\QueryException;

class GroupQuery extends Query
{
    protected array $subqueries = [];
    protected string $joint;

    /**
     * @param array<Query> $subqueries
     */
    public function __construct(array $subqueries, protected float $boost = 1.0)
    {
        foreach ($subqueries as $subquery) {
            if (!($subquery instanceof Query)) {
                throw new QueryException(sprintf('Invalid subquery %s', get_class($subquery)));
            }
        }
        $this->subqueries = $subqueries;
    }

    public function normalize(): self
    {
        $normalized = [];
        foreach ($this->subqueries as $subquery) {
            $normalized[] = $subquery->normalize();
        }
        return new static($normalized, $this->boost);
    }

    /**
     * @return array<Query>
     */
    public function getSubqueries(): array
    {
        return $this->subqueries;
    }

    public function toString(): string
    {
        $strings = array_map(fn($subquery) => $subquery->toString(), $this->subqueries);
        return "(" . implode(" {$this->joint} ", $strings) . ")";
    }
}
