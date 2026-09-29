<?php

namespace App\Support\Formula;

/**
 * Tokenizer and recursive descent parser for the Formula column's expression language, the PHP
 * twin of the frontend's `formulaParser.ts`, so automations can read a formula's result on the
 * server. Nodes are plain arrays with a `type`: `number`, `string`, `column` (`ref`), `constant`,
 * `unary` (`operator`, `operand`), `binary` (`operator`, `left`, `right`) and `call` (`name`, `args`).
 *
 * Grammar, lowest to highest precedence: comparison (= <> != < <= > >=), concat (&), additive
 * (+ -), term (* /), unary (- +), power (^, right associative), primary.
 */
class FormulaParser
{
    private const COMPARISON_OPERATORS = ['=', '<>', '!=', '<', '<=', '>', '>='];

    private const OPERATOR_CHARS = ['+', '-', '*', '/', '^', '&', '=', '<', '>', '!'];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** @var array<int, array{type: string, text: string}> */
    private array $tokens = [];

    private int $position = 0;

    /**
     * Parses `$source` into a tree, throwing a {@see FormulaError} with the `#ERROR!` code on bad input.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $source): array
    {
        if (isset(self::$cache[$source])) {
            return self::$cache[$source];
        }

        $parser = new self;
        $parser->tokens = array_values(array_filter(self::tokenize($source), fn (array $token) => $token['type'] !== 'space'));
        $tree = $parser->parseRoot();

        if (count(self::$cache) >= 200) {
            self::$cache = [];
        }

        return self::$cache[$source] = $tree;
    }

    /**
     * @return array<int, array{type: string, text: string}>
     */
    public static function tokenize(string $source): array
    {
        $chars = mb_str_split($source);
        $length = count($chars);
        $tokens = [];
        $index = 0;
        $slice = fn (int $start, int $end) => implode('', array_slice($chars, $start, $end - $start));
        $is_digit = fn (?string $char) => $char !== null && ctype_digit($char);

        while ($index < $length) {
            $char = $chars[$index];

            if (trim($char) === '') {
                $end = $index + 1;
                while ($end < $length && trim($chars[$end]) === '') {
                    $end++;
                }
                $tokens[] = ['type' => 'space', 'text' => $slice($index, $end)];
                $index = $end;
            } elseif ($is_digit($char) || ($char === '.' && $is_digit($chars[$index + 1] ?? null))) {
                $end = $index;
                while ($end < $length && $is_digit($chars[$end])) {
                    $end++;
                }
                if (($chars[$end] ?? null) === '.') {
                    $end++;
                    while ($end < $length && $is_digit($chars[$end])) {
                        $end++;
                    }
                }
                $tokens[] = ['type' => 'number', 'text' => $slice($index, $end)];
                $index = $end;
            } elseif ($char === '"' || $char === "'") {
                $end = $index + 1;
                $is_closed = false;
                while ($end < $length) {
                    if ($chars[$end] === $char) {
                        if (($chars[$end + 1] ?? null) === $char) {
                            $end += 2;

                            continue;
                        }
                        $is_closed = true;
                        $end++;
                        break;
                    }
                    $end++;
                }
                $tokens[] = ['type' => $is_closed ? 'string' : 'invalid', 'text' => $slice($index, $end)];
                $index = $end;
            } elseif ($char === '{') {
                $close = $index + 1;
                while ($close < $length && $chars[$close] !== '}') {
                    $close++;
                }
                if ($close >= $length) {
                    $tokens[] = ['type' => 'invalid', 'text' => $slice($index, $length)];
                    $index = $length;
                } else {
                    $tokens[] = ['type' => 'column', 'text' => $slice($index, $close + 1)];
                    $index = $close + 1;
                }
            } elseif (preg_match('/[A-Za-z_]/', $char) === 1) {
                $end = $index + 1;
                while ($end < $length && preg_match('/[A-Za-z0-9_]/', $chars[$end]) === 1) {
                    $end++;
                }
                $tokens[] = ['type' => 'name', 'text' => $slice($index, $end)];
                $index = $end;
            } elseif ($char === '(' || $char === ')') {
                $tokens[] = ['type' => 'paren', 'text' => $char];
                $index++;
            } elseif ($char === ',' || $char === ';') {
                $tokens[] = ['type' => 'comma', 'text' => $char];
                $index++;
            } elseif (in_array($char, self::OPERATOR_CHARS, true)) {
                $pair = $slice($index, $index + 2);
                $is_two_char = in_array($pair, ['<>', '<=', '>=', '!='], true);
                $tokens[] = ['type' => 'operator', 'text' => $is_two_char ? $pair : $char];
                $index += $is_two_char ? 2 : 1;
            } else {
                $tokens[] = ['type' => 'invalid', 'text' => $char];
                $index++;
            }
        }

        return $tokens;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRoot(): array
    {
        if ($this->tokens === []) {
            throw new FormulaError('#ERROR!', 'Enter a formula.');
        }
        $node = $this->parseComparison();
        $extra = $this->peek();
        if ($extra !== null) {
            throw new FormulaError('#ERROR!', $extra['text'] === ')' ? 'Unexpected closing parenthesis.' : "Unexpected \"{$extra['text']}\".");
        }

        return $node;
    }

    /**
     * @return array{type: string, text: string}|null
     */
    private function peek(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    /**
     * @return array{type: string, text: string}|null
     */
    private function next(): ?array
    {
        return $this->tokens[$this->position++] ?? null;
    }

    private function isOperator(string ...$operators): bool
    {
        $token = $this->peek();

        return $token !== null && $token['type'] === 'operator' && in_array($token['text'], $operators, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseComparison(): array
    {
        $left = $this->parseConcat();
        while (($token = $this->peek()) !== null && $token['type'] === 'operator' && in_array($token['text'], self::COMPARISON_OPERATORS, true)) {
            $this->next();
            $right = $this->parseConcat();
            $left = ['type' => 'binary', 'operator' => $token['text'] === '!=' ? '<>' : $token['text'], 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseConcat(): array
    {
        $left = $this->parseAdditive();
        while ($this->isOperator('&')) {
            $this->next();
            $left = ['type' => 'binary', 'operator' => '&', 'left' => $left, 'right' => $this->parseAdditive()];
        }

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAdditive(): array
    {
        $left = $this->parseTerm();
        while ($this->isOperator('+', '-')) {
            $operator = $this->next()['text'];
            $left = ['type' => 'binary', 'operator' => $operator, 'left' => $left, 'right' => $this->parseTerm()];
        }

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTerm(): array
    {
        $left = $this->parseUnary();
        while ($this->isOperator('*', '/')) {
            $operator = $this->next()['text'];
            $left = ['type' => 'binary', 'operator' => $operator, 'left' => $left, 'right' => $this->parseUnary()];
        }

        return $left;
    }

    /**
     * Unary minus binds looser than `^`, so `-2^2` is -4 like in maths.
     *
     * @return array<string, mixed>
     */
    private function parseUnary(): array
    {
        if ($this->isOperator('-', '+')) {
            $operator = $this->next()['text'];

            return ['type' => 'unary', 'operator' => $operator, 'operand' => $this->parseUnary()];
        }

        return $this->parsePower();
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePower(): array
    {
        $base = $this->parsePrimary();
        if ($this->isOperator('^')) {
            $this->next();

            return ['type' => 'binary', 'operator' => '^', 'left' => $base, 'right' => $this->parseUnary()];
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePrimary(): array
    {
        $token = $this->next();
        if ($token === null) {
            throw new FormulaError('#ERROR!', 'The formula ends unexpectedly.');
        }

        switch ($token['type']) {
            case 'number':
                return ['type' => 'number', 'value' => (float) $token['text']];
            case 'string':
                $quote = $token['text'][0];

                return ['type' => 'string', 'value' => str_replace($quote.$quote, $quote, substr($token['text'], 1, -1))];
            case 'column':
                $ref = trim(substr($token['text'], 1, -1));
                if ($ref === '') {
                    throw new FormulaError('#ERROR!', 'Name a column between the braces.');
                }

                return ['type' => 'column', 'ref' => $ref];
            case 'name':
                return $this->parseNameOrCall($token);
            case 'paren':
                if ($token['text'] === ')') {
                    throw new FormulaError('#ERROR!', 'Unexpected closing parenthesis.');
                }
                $inner = $this->parseComparison();
                $this->expectClosingParen();

                return $inner;
            case 'invalid':
                throw new FormulaError('#ERROR!', match (true) {
                    str_starts_with($token['text'], '"'), str_starts_with($token['text'], "'") => 'This text is missing its closing quote.',
                    str_starts_with($token['text'], '{') => 'This column reference is missing its closing brace.',
                    default => "Unexpected character \"{$token['text']}\".",
                });
            default:
                throw new FormulaError('#ERROR!', "Unexpected \"{$token['text']}\".");
        }
    }

    /**
     * @param  array{type: string, text: string}  $name_token
     * @return array<string, mixed>
     */
    private function parseNameOrCall(array $name_token): array
    {
        $upper = strtoupper($name_token['text']);
        $open = $this->peek();

        if ($open !== null && $open['type'] === 'paren' && $open['text'] === '(') {
            $this->next();
            $args = [];
            $after_open = $this->peek();
            if ($after_open !== null && $after_open['type'] === 'paren' && $after_open['text'] === ')') {
                $this->next();

                return ['type' => 'call', 'name' => $upper, 'args' => $args];
            }

            while (true) {
                $args[] = $this->parseComparison();
                $separator = $this->peek();
                if ($separator !== null && $separator['type'] === 'comma') {
                    $this->next();

                    continue;
                }
                break;
            }
            $this->expectClosingParen();

            return ['type' => 'call', 'name' => $upper, 'args' => $args];
        }

        if ($upper === 'TRUE' || $upper === 'FALSE') {
            return ['type' => 'constant', 'value' => $upper === 'TRUE'];
        }

        throw new FormulaError('#ERROR!', "\"{$name_token['text']}\" is not a function or a value.");
    }

    private function expectClosingParen(): void
    {
        $token = $this->peek();
        if ($token !== null && $token['type'] === 'paren' && $token['text'] === ')') {
            $this->next();

            return;
        }

        throw new FormulaError('#ERROR!', 'This parenthesis is never closed.');
    }
}
