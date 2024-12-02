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

abstract class Query implements \Stringable
{
    protected int $priority = 0;

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        return '<undefined>';
    }

    public function getPriority(): int
    {
        return $this->priority;
    }
}
