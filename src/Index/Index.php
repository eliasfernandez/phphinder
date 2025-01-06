<?php

namespace PHPhinder\Index;

interface Index
{
    public function open(): void;
    public function close(): void;
    public function isCreated(): bool;
    public function isEmpty(): bool;
    public function drop(): void;

    public function getSchemaOptions(): int;
}
