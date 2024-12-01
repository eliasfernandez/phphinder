<?php

namespace SearchEngine\Query;

final class AndQuery extends GroupQuery
{
    protected string $joint = "AND";
}
