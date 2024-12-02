<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
