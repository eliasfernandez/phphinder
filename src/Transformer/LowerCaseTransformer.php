<?php

namespace SearchEngine\Transformer;

use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

class LowerCaseTransformer implements Transformer
{
    /** @var Filter[] */
    private array $filters = [];

    public function __construct($langIso = 'en', string ...$filters)
    {
        foreach ($filters as $filter) {
            $this->filters []= new $filter($langIso);
        }
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
