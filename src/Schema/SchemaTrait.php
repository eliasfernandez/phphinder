<?php

namespace SearchEngine\Schema;

use SearchEngine\Transformer\Transformer;

trait SchemaTrait
{
    /** @var array|Transformer[]  */
    public array $transformers;

    public function __construct(Transformer ...$transformers)
    {
        $this->transformers = $transformers;
    }

    /**
     * @return Transformer[]
     */
    public function getTransformers(): array
    {
        return $this->transformers;
    }
}
