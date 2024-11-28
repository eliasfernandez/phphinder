<?php

namespace SearchEngine\Transformer;

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
