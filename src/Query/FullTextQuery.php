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

final class FullTextQuery extends TextQuery
{
    public function __construct(
        protected readonly string $field,
        protected string $value,
        protected float $boost = 1.0,
    ) {
        $this->value = str_replace('"', '', $value);
    }

    public function toString(): string
    {
        return "{$this->field}:\"{$this->value}\"";
    }
}
