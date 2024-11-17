<?php

namespace SearchEngine;

class Document
{
    public function __construct(public int $id, public string $text)
    {
    }
}
