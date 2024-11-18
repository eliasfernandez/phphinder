<?php

namespace SearchEngine\Transformer;

use SearchEngine\SearchEngine;

interface Filter
{
    public function allow(string $term): bool;
}
