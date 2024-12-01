<?php

namespace SearchEngine\Query;

final class OrQuery extends GroupQuery
{
    protected string $joint = "OR";
}
