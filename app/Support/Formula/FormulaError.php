<?php

namespace App\Support\Formula;

use RuntimeException;

/**
 * A formula that cannot be read or evaluated, with the spreadsheet style code a cell would show
 * (`#DIV/0!`, `#VALUE!`, `#NAME?`, `#REF!`, `#NUM!`, `#N/A` or `#ERROR!` for a syntax problem).
 */
class FormulaError extends RuntimeException
{
    public function __construct(public readonly string $error_code, string $message)
    {
        parent::__construct($message);
    }
}
