<?php

namespace SearchEngine\Transformer;

use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

class LowerCaseTransformer implements Transformer
{
    use TransformerTrait;

    /** @var Filter[] */
    private array $filters = [];

    public function __construct(string $langIso = 'en', string ...$filters)
    {
        $this->loadFilters($filters, $langIso);
    }

    public function apply(string $term): ?string
    {
        foreach ($this->filters as $filter) {
            if (!$filter->allow($term)) {
                return null;
            }
        }

        return mb_strtolower($term);
    }
}
