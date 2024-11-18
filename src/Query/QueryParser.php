<?php

namespace SearchEngine\Query;

use SearchEngine\Schema\Schema;

class QueryParser
{
    public function __construct(
        private string $fieldName,
        private ?Schema $schema,
    ) {
    }

    /**
     * 1. Tokenize the input query string.
     * 2. Parse the tokens recursively:
     *  - Handle AND and OR operators.
     *  - Handle fielded terms (field:value).
     *  - Handle prefix queries (e.g., term*).
     *  - Handle parentheses for grouping subqueries.
     * 3. Build and return the appropriate Query object based on
     *    the parsed tokens.
     *
     */
    public function parse(string $text): ?Query
    {
        $tokens = $this->tokenize($text);
        $query = $this->parseTokens($tokens);
        //eval(\Psy\sh());
        return $query;
    }

    private function tokenize(string $query): array
    {
        // Split the query into tokens: whitespace, operators, parentheses, field:value, etc.
        $tokens =  preg_split('/(\s+|OR|\(|\)|\w+\*|\w+:\w+\*|\w+:\w+)/', $query, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        return array_values(array_filter($tokens, fn ($token) => trim($token) !== ''));
    }

    private function parseTokens(array &$tokens, &$pointer = 0): Query
    {
        if (count($tokens) === 0) {
            return new NullQuery();
        }
        $operatorStack = [];
        $subqueries = [];

        while ($pointer < count($tokens)) {
            $token = trim($tokens[$pointer]);
            if ($token === '(') {
                // Handle opening parenthesis: Parse a subquery recursively
                $originalPointer = $pointer;
                $pointer ++;
                $subquery = $this->parseTokens($tokens, $pointer);
                $subqueries[] = $subquery;
                $tokens = array_merge(
                    array_slice($tokens, 0, $originalPointer),
                    array_slice($tokens, $pointer),
                );
                $pointer = $originalPointer;
            } elseif ($token === ')') {
                break;
                // Handle closing parenthesis: Return current subquery group
            } elseif (strtoupper($token) === 'OR') {
                // Handle OR operator: Create an OR query from the subqueries
                $operatorStack[] = 'OR';
            } elseif (preg_match('/^(\w+)\*$/', $token, $matches)) {
                // Handle PrefixQuery (e.g., term*)
                $subqueries[] = new PrefixQuery($this->fieldName, $matches[1]);
            } elseif (str_contains($token, ':')) {
                // Handle fielded query (e.g., field:value)
                [$field, $value] = explode(':', $token, 2);
                if (preg_match('/^(\w+)\*$/', $value, $matches)) {
                    $subqueries[] = new PrefixQuery($field, $matches[1]);
                } else {
                    $subqueries[] = new TermQuery($field, $value);
                }
            } else {
                // Handle a plain term (e.g., render, shade, animate)
                $subqueries[] = new TermQuery($this->fieldName, $token);
            }
            $pointer++;
        }

        if (count($subqueries) === 1) {
            return reset($subqueries);
        }
        // If there was an "OR" operator, combine the queries
        if (in_array('OR', $operatorStack)) {
            return new OrQuery($subqueries);
        }

        // Otherwise, default to "AND" query
        return new AndQuery($subqueries);
    }

}
