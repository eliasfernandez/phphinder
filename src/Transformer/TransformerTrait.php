<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Transformer;

trait TransformerTrait
{
    /**
     * @param array<string> $filters
     */
    private function loadFilters(array $filters, string $langIso): void
    {
        foreach ($filters as $filter) {
            if (!class_exists($filter)) {
                throw new \InvalidArgumentException(sprintf("`%s` must be a valid class", $filter));
            }

            $object = new $filter($langIso);
            if (!$object instanceof Filter) {
                throw new \InvalidArgumentException(sprintf("`%s` must implement %s", $filter, Filter::class));
            }

            $this->filters [] = $object;
        }
    }
}
