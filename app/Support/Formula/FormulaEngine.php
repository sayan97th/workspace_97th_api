<?php

namespace App\Support\Formula;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Evaluates a parsed Formula column expression against one item, the PHP twin of the frontend's
 * `formulaEngine.ts`, `formulaValues.ts` and `formulaFunctions.ts`, with the same functions, the
 * same coercion rules and the same results, so an automation condition reads the value a person
 * sees in the cell.
 *
 * A value is null (blank), an int or float, a string, a bool or a {@see CarbonImmutable} date.
 * Dates are wall clock dates in the app's time zone.
 */
class FormulaEngine
{
    /** The pseudo column that reads the item's own name. */
    public const NAME_COLUMN = '__name';

    /** Column types a formula can read, anything else has no single sensible value. */
    public const SOURCE_TYPES = [
        'text', 'long_text', 'phone', 'email', 'link', 'number', 'status', 'label', 'dropdown', 'tags',
        'date', 'checkbox', 'rating', 'progress', 'auto_number', 'people', 'vote',
    ];

    private const MAX_TEXT_LENGTH = 10000;

    private const MAX_WORKDAY_SPAN = 36600;

    /** @var array<string, array{0: int, 1: int}> function name => the fewest and most arguments it takes */
    private const ARITY = [
        'IF' => [2, 3], 'IFS' => [2, PHP_INT_MAX], 'SWITCH' => [3, PHP_INT_MAX], 'IFERROR' => [2, 2],
        'AND' => [1, PHP_INT_MAX], 'OR' => [1, PHP_INT_MAX], 'XOR' => [1, PHP_INT_MAX], 'NOT' => [1, 1],
        'ISBLANK' => [1, 1], 'TRUE' => [0, 0], 'FALSE' => [0, 0],
        'SUM' => [1, PHP_INT_MAX], 'AVERAGE' => [1, PHP_INT_MAX], 'MIN' => [1, PHP_INT_MAX], 'MAX' => [1, PHP_INT_MAX],
        'COUNT' => [1, PHP_INT_MAX], 'ROUND' => [1, 2], 'ROUNDUP' => [1, 2], 'ROUNDDOWN' => [1, 2],
        'CEILING' => [1, 2], 'FLOOR' => [1, 2], 'INT' => [1, 1], 'ABS' => [1, 1], 'SQRT' => [1, 1],
        'POWER' => [2, 2], 'MOD' => [2, 2], 'LOG' => [1, 2], 'EXP' => [1, 1],
        'CONCATENATE' => [1, PHP_INT_MAX], 'CONCAT' => [1, PHP_INT_MAX], 'LEFT' => [1, 2], 'RIGHT' => [1, 2],
        'MID' => [3, 3], 'LEN' => [1, 1], 'LOWER' => [1, 1], 'UPPER' => [1, 1], 'TRIM' => [1, 1],
        'SUBSTITUTE' => [3, 4], 'REPLACE' => [4, 4], 'FIND' => [2, 3], 'SEARCH' => [2, 3], 'REPT' => [2, 2],
        'VALUE' => [1, 1], 'TODAY' => [0, 0], 'NOW' => [0, 0], 'DATE' => [3, 3], 'YEAR' => [1, 1],
        'MONTH' => [1, 1], 'DAY' => [1, 1], 'HOUR' => [1, 1], 'MINUTE' => [1, 1], 'WEEKDAY' => [1, 1],
        'WEEKNUM' => [1, 1], 'DAYS' => [2, 2], 'ADD_DAYS' => [2, 2], 'SUBTRACT_DAYS' => [2, 2],
        'WORKDAYS' => [2, 2], 'HOURS_DIFF' => [2, 2], 'MINUTES_DIFF' => [2, 2], 'FORMAT_DATE' => [1, 2],
    ];

    /**
     * @param  array<string, array{kind: string, title: string, options: array<string, string>}>  $sources  column id => what the formula reads from it
     * @param  array<string, mixed>  $values  column id => the item's stored value
     */
    public function __construct(
        private readonly array $sources,
        private readonly array $values,
        private readonly string $item_name = '',
    ) {}

    /**
     * The expression's result for the item, or the error it raised.
     *
     * @return array{ok: true, value: mixed, text: string}|array{ok: false, code: string, message: string}
     */
    public function run(string $expression): array
    {
        try {
            $value = $this->evaluate(FormulaParser::parse($expression));

            return ['ok' => true, 'value' => $value, 'text' => self::format($value)];
        } catch (FormulaError $error) {
            return ['ok' => false, 'code' => $error->error_code, 'message' => $error->getMessage()];
        } catch (Throwable) {
            return ['ok' => false, 'code' => '#ERROR!', 'message' => 'The formula could not be evaluated.'];
        }
    }

    // ── Values ────────────────────────────────────────────────────────────────

    /**
     * How a value is shown in a cell and when joined into text.
     */
    public static function format(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return self::formatNumber(self::roundResult((float) $value));
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if ($value instanceof CarbonImmutable) {
            $has_time = $value->hour !== 0 || $value->minute !== 0 || $value->second !== 0;

            return $has_time ? $value->format('Y-m-d H:i') : $value->format('Y-m-d');
        }

        return (string) $value;
    }

    private static function formatNumber(float $number): string
    {
        if (floor($number) === $number && abs($number) < 1e15) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
    }

    public static function roundResult(float $number): float
    {
        return round($number * 1e6) / 1e6;
    }

    public static function toNumber(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if ($value === null) {
            return 0.0;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if ($value instanceof CarbonImmutable) {
            throw new FormulaError('#VALUE!', 'A date cannot be used as a number here. Subtract two dates to get a number of days.');
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return 0.0;
        }
        if (! is_numeric($trimmed)) {
            throw new FormulaError('#VALUE!', "\"{$value}\" is not a number.");
        }

        return (float) $trimmed;
    }

    public static function toText(mixed $value): string
    {
        return self::format($value);
    }

    public static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        if ($value === null) {
            return false;
        }
        if ($value instanceof CarbonImmutable) {
            return true;
        }
        $lowered = mb_strtolower(trim((string) $value));
        if ($lowered === 'true') {
            return true;
        }
        if ($lowered === 'false' || $lowered === '') {
            return false;
        }

        throw new FormulaError('#VALUE!', "\"{$value}\" is not TRUE or FALSE.");
    }

    public static function toDate(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }
        if (is_string($value) && ($parsed = self::parseDate($value)) !== null) {
            return $parsed;
        }

        throw new FormulaError('#VALUE!', ($value === null ? 'A blank value' : '"'.self::format($value).'"').' is not a date.');
    }

    public static function parseDate(string $text): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/', trim($text), $match) !== 1) {
            return null;
        }

        return CarbonImmutable::create((int) $match[1], (int) $match[2], (int) $match[3], (int) ($match[4] ?? 0), (int) ($match[5] ?? 0), (int) ($match[6] ?? 0));
    }

    public static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Spreadsheet style ordering: dates by time, numbers numerically, text without case, and
     * across types number < text < boolean. A blank equals the empty value it is compared with.
     */
    public static function compare(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a instanceof CarbonImmutable || $b instanceof CarbonImmutable) {
            if ($a === null) {
                return -1;
            }
            if ($b === null) {
                return 1;
            }
            $left = $a instanceof CarbonImmutable ? $a : self::parseDate(self::toText($a));
            $right = $b instanceof CarbonImmutable ? $b : self::parseDate(self::toText($b));
            if ($left !== null && $right !== null) {
                return $left->getTimestamp() <=> $right->getTimestamp();
            }

            return self::compare(self::toText($a), self::toText($b));
        }

        $is_number = fn (mixed $value) => is_int($value) || is_float($value);
        $left = $a === null ? ($is_number($b) ? 0 : (is_bool($b) ? false : '')) : $a;
        $right = $b === null ? ($is_number($a) ? 0 : (is_bool($a) ? false : '')) : $b;
        $rank = fn (mixed $value) => $is_number($value) ? 0 : (is_string($value) ? 1 : 2);

        if ($rank($left) !== $rank($right) && ($is_number($left) || $is_number($right))) {
            $text = is_string($left) ? $left : (is_string($right) ? $right : null);
            if ($text !== null && trim($text) !== '' && is_numeric(trim($text))) {
                return (float) $left <=> (float) $right;
            }
        }
        if ($rank($left) !== $rank($right)) {
            return $rank($left) <=> $rank($right);
        }
        if (is_string($left)) {
            return strcmp(mb_strtolower($left), mb_strtolower((string) $right)) <=> 0;
        }

        return (float) $left <=> (float) $right;
    }

    private static function daysBetween(CarbonImmutable $end, CarbonImmutable $start): float
    {
        return self::roundResult(self::wallSeconds($end) / 86400 - self::wallSeconds($start) / 86400);
    }

    /** Seconds of the date's wall clock fields as if they were UTC, so differences ignore daylight saving jumps. */
    private static function wallSeconds(CarbonImmutable $date): int
    {
        return (int) gmmktime($date->hour, $date->minute, $date->second, $date->month, $date->day, $date->year);
    }

    private static function shiftDate(CarbonImmutable $date, float $days): CarbonImmutable
    {
        $whole = (int) $days;

        return $date->addDays($whole)->addMinutes((int) round(($days - $whole) * 1440));
    }

    // ── Evaluation ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $node
     */
    private function evaluate(array $node): mixed
    {
        switch ($node['type']) {
            case 'number':
            case 'string':
            case 'constant':
                return $node['value'];
            case 'column':
                return $this->readColumn((string) $node['ref']);
            case 'unary':
                $operand = self::toNumber($this->evaluate($node['operand']));

                return $node['operator'] === '-' ? -$operand : $operand;
            case 'binary':
                $left = $this->evaluate($node['left']);
                $right = $this->evaluate($node['right']);

                return match ($node['operator']) {
                    '&' => $this->clampText(self::toText($left).self::toText($right)),
                    '=' => self::compare($left, $right) === 0,
                    '<>' => self::compare($left, $right) !== 0,
                    '<' => self::compare($left, $right) < 0,
                    '<=' => self::compare($left, $right) <= 0,
                    '>' => self::compare($left, $right) > 0,
                    '>=' => self::compare($left, $right) >= 0,
                    default => $this->arithmetic((string) $node['operator'], $left, $right),
                };
            case 'call':
                return $this->call((string) $node['name'], $node['args']);
        }

        throw new FormulaError('#ERROR!', 'The formula could not be evaluated.');
    }

    private function readColumn(string $ref): mixed
    {
        $id = str_starts_with($ref, '#') ? substr($ref, 1) : null;
        if ($id === null) {
            $wanted = mb_strtolower(preg_replace('/\s+/', ' ', trim($ref)) ?? '');
            $matches = array_keys(array_filter($this->sources, fn (array $source) => mb_strtolower(preg_replace('/\s+/', ' ', trim($source['title'])) ?? '') === $wanted));
            if (count($matches) !== 1) {
                throw new FormulaError('#REF!', count($matches) > 1 ? "More than one column is named \"{$ref}\"." : "There is no column named \"{$ref}\".");
            }
            $id = (string) $matches[0];
        }

        if ($id === self::NAME_COLUMN) {
            return $this->item_name !== '' ? $this->item_name : null;
        }
        $source = $this->sources[$id] ?? null;
        if ($source === null) {
            throw new FormulaError('#REF!', 'This column no longer exists or cannot be used in a formula.');
        }

        return $this->readSourceValue($source, $this->values[$id] ?? null);
    }

    /**
     * One stored cell value as the formula sees it, according to the column's type.
     *
     * @param  array{kind: string, title: string, options: array<string, string>}  $source
     */
    private function readSourceValue(array $source, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }
        $label = fn (string $id) => $source['options'][$id] ?? $id;

        return match ($source['kind']) {
            'number', 'rating', 'progress', 'auto_number' => $raw === '' || ! is_numeric($raw) ? null : $raw + 0,
            'checkbox' => $raw === true || $raw === 'true' || $raw === '1' || $raw === 1,
            'date' => is_string($raw) ? self::parseDate($raw) : null,
            'status', 'label' => is_scalar($raw) && (string) $raw !== '' ? $label((string) $raw) : null,
            'dropdown', 'tags' => is_array($raw)
                ? ($raw === [] ? null : implode(', ', array_map(fn ($id) => $label((string) $id), array_filter($raw, 'is_scalar'))))
                : (is_scalar($raw) && (string) $raw !== '' ? $label((string) $raw) : null),
            'people', 'vote' => is_array($raw) ? count($raw) : 0,
            'link' => is_array($raw) ? (($raw['text'] ?? '') ?: (($raw['url'] ?? '') ?: null)) : (is_string($raw) ? $raw : null),
            default => is_string($raw) ? ($raw === '' ? null : $raw) : (is_int($raw) || is_float($raw) ? $raw : null),
        };
    }

    private function arithmetic(string $operator, mixed $left, mixed $right): mixed
    {
        $left_is_date = $left instanceof CarbonImmutable;
        $right_is_date = $right instanceof CarbonImmutable;

        if (($operator === '+' || $operator === '-') && (($left_is_date && $right === null) || ($right_is_date && $left === null))) {
            return null;
        }
        if ($operator === '-' && $left_is_date && $right_is_date) {
            return self::daysBetween($left, $right);
        }
        if (($operator === '+' || $operator === '-') && ($left_is_date || $right_is_date)) {
            if ($left_is_date && ! $right_is_date) {
                return self::shiftDate($left, $operator === '+' ? self::toNumber($right) : -self::toNumber($right));
            }
            if ($operator === '+' && $right_is_date) {
                return self::shiftDate($right, self::toNumber($left));
            }
            throw new FormulaError('#VALUE!', 'Two dates can only be subtracted, not added.');
        }

        $a = self::toNumber($left);
        $b = self::toNumber($right);

        return match ($operator) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
            '/' => $b == 0 ? throw new FormulaError('#DIV/0!', 'Cannot divide by zero.') : $a / $b,
            '^' => $this->checkNumber($a ** $b),
            default => throw new FormulaError('#ERROR!', "Unknown operator {$operator}."),
        };
    }

    private function checkNumber(float|int $number, string $message = 'The result is not a valid number.'): float
    {
        if (! is_finite((float) $number)) {
            throw new FormulaError('#NUM!', $message);
        }

        return (float) $number;
    }

    private function clampText(string $text): string
    {
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new FormulaError('#VALUE!', 'The text result is too long.');
        }

        return $text;
    }

    /**
     * @param  array<int, array<string, mixed>>  $args
     */
    private function call(string $name, array $args): mixed
    {
        if (! isset(self::ARITY[$name])) {
            throw new FormulaError('#NAME?', "{$name} is not a known function.");
        }
        [$min, $max] = self::ARITY[$name];
        if (count($args) < $min || count($args) > $max) {
            throw new FormulaError('#VALUE!', "{$name} got the wrong number of arguments.");
        }

        $lazy = $this->callLazy($name, $args);
        if ($lazy['handled']) {
            return $lazy['value'];
        }

        $values = array_map(fn (array $arg) => $this->evaluate($arg), $args);

        return $this->callEager($name, $values);
    }

    /**
     * The functions that decide which arguments to evaluate, so an `IF` never evaluates the branch it skips.
     *
     * @param  array<int, array<string, mixed>>  $args
     * @return array{handled: bool, value: mixed}
     */
    private function callLazy(string $name, array $args): array
    {
        switch ($name) {
            case 'IF':
                $value = self::toBoolean($this->evaluate($args[0])) ? $this->evaluate($args[1]) : (isset($args[2]) ? $this->evaluate($args[2]) : false);

                return ['handled' => true, 'value' => $value];
            case 'IFS':
                if (count($args) % 2 !== 0) {
                    throw new FormulaError('#VALUE!', 'IFS needs a value after every condition.');
                }
                for ($index = 0; $index < count($args); $index += 2) {
                    if (self::toBoolean($this->evaluate($args[$index]))) {
                        return ['handled' => true, 'value' => $this->evaluate($args[$index + 1])];
                    }
                }
                throw new FormulaError('#N/A', 'None of the IFS conditions is true.');
            case 'SWITCH':
                $subject = $this->evaluate($args[0]);
                $pairs = array_slice($args, 1);
                $has_default = count($pairs) % 2 === 1;
                $pair_count = $has_default ? count($pairs) - 1 : count($pairs);
                for ($index = 0; $index < $pair_count; $index += 2) {
                    if (self::compare($subject, $this->evaluate($pairs[$index])) === 0) {
                        return ['handled' => true, 'value' => $this->evaluate($pairs[$index + 1])];
                    }
                }
                if ($has_default) {
                    return ['handled' => true, 'value' => $this->evaluate($pairs[count($pairs) - 1])];
                }
                throw new FormulaError('#N/A', 'No SWITCH match was found and no default was given.');
            case 'IFERROR':
                try {
                    return ['handled' => true, 'value' => $this->evaluate($args[0])];
                } catch (FormulaError) {
                    return ['handled' => true, 'value' => $this->evaluate($args[1])];
                }
            default:
                return ['handled' => false, 'value' => null];
        }
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function callEager(string $name, array $args): mixed
    {
        $numbers = fn () => array_map(fn ($arg) => self::toNumber($arg), array_values(array_filter($args, fn ($arg) => ! self::isBlank($arg))));
        $digits = fn (int $index) => array_key_exists($index, $args) ? self::toNumber($args[$index]) : 0.0;
        $text = fn (int $index) => self::toText($args[$index] ?? null);

        return match ($name) {
            'AND' => collect($args)->every(fn ($arg) => self::toBoolean($arg)),
            'OR' => collect($args)->contains(fn ($arg) => self::toBoolean($arg)),
            'XOR' => count(array_filter($args, fn ($arg) => self::toBoolean($arg))) % 2 === 1,
            'NOT' => ! self::toBoolean($args[0]),
            'ISBLANK' => self::isBlank($args[0]),
            'TRUE' => true,
            'FALSE' => false,
            'SUM' => array_sum(array_map(fn ($arg) => self::toNumber($arg), $args)),
            'AVERAGE' => ($list = $numbers()) === [] ? throw new FormulaError('#DIV/0!', 'There are no numbers to average.') : array_sum($list) / count($list),
            'MIN' => ($list = $numbers()) === [] ? 0 : min($list),
            'MAX' => ($list = $numbers()) === [] ? 0 : max($list),
            'COUNT' => count(array_filter($args, fn ($arg) => is_int($arg) || is_float($arg))),
            'ROUND' => $this->scaledRound(self::toNumber($args[0]), $digits(1), 'nearest'),
            'ROUNDUP' => $this->scaledRound(self::toNumber($args[0]), $digits(1), 'up'),
            'ROUNDDOWN' => $this->scaledRound(self::toNumber($args[0]), $digits(1), 'down'),
            'CEILING' => ($step = array_key_exists(1, $args) ? abs(self::toNumber($args[1])) : 1.0) == 0 ? 0 : ceil(self::toNumber($args[0]) / $step) * $step,
            'FLOOR' => ($step = array_key_exists(1, $args) ? abs(self::toNumber($args[1])) : 1.0) == 0 ? 0 : floor(self::toNumber($args[0]) / $step) * $step,
            'INT' => floor(self::toNumber($args[0])),
            'ABS' => abs(self::toNumber($args[0])),
            'SQRT' => self::toNumber($args[0]) < 0 ? throw new FormulaError('#NUM!', 'The square root of a negative number is not real.') : sqrt(self::toNumber($args[0])),
            'POWER' => $this->checkNumber(self::toNumber($args[0]) ** self::toNumber($args[1])),
            'MOD' => ($by = self::toNumber($args[1])) == 0 ? throw new FormulaError('#DIV/0!', 'The divisor cannot be zero.') : self::toNumber($args[0]) - $by * floor(self::toNumber($args[0]) / $by),
            'LOG' => $this->log(self::toNumber($args[0]), array_key_exists(1, $args) ? self::toNumber($args[1]) : 10.0),
            'EXP' => $this->checkNumber(exp(self::toNumber($args[0]))),
            'CONCATENATE', 'CONCAT' => $this->clampText(implode('', array_map(fn ($arg) => self::toText($arg), $args))),
            'LEFT' => mb_substr($text(0), 0, (int) max(0, array_key_exists(1, $args) ? self::toNumber($args[1]) : 1)),
            'RIGHT' => ($count = (int) max(0, array_key_exists(1, $args) ? self::toNumber($args[1]) : 1)) === 0 ? '' : mb_substr($text(0), -$count),
            'MID' => mb_substr($text(0), (int) max(1, self::toNumber($args[1])) - 1, (int) max(0, self::toNumber($args[2]))),
            'LEN' => mb_strlen($text(0)),
            'LOWER' => mb_strtolower($text(0)),
            'UPPER' => mb_strtoupper($text(0)),
            'TRIM' => preg_replace('/\s+/u', ' ', trim($text(0))) ?? '',
            'SUBSTITUTE' => $this->substitute($text(0), $text(1), $text(2), array_key_exists(3, $args) ? (int) self::toNumber($args[3]) : null),
            'REPLACE' => $this->clampText(mb_substr($text(0), 0, (int) max(1, self::toNumber($args[1])) - 1).$text(3).mb_substr($text(0), (int) max(1, self::toNumber($args[1])) - 1 + (int) max(0, self::toNumber($args[2])))),
            'FIND' => $this->find($text(0), $text(1), array_key_exists(2, $args) ? (int) self::toNumber($args[2]) : 1, false),
            'SEARCH' => $this->find($text(0), $text(1), array_key_exists(2, $args) ? (int) self::toNumber($args[2]) : 1, true),
            'REPT' => ($times = max(0, (int) self::toNumber($args[1]))) * mb_strlen($text(0)) > self::MAX_TEXT_LENGTH ? throw new FormulaError('#VALUE!', 'The text result is too long.') : str_repeat($text(0), $times),
            'VALUE' => self::toNumber($args[0]),
            'TODAY' => CarbonImmutable::today(),
            'NOW' => CarbonImmutable::now(),
            'DATE' => CarbonImmutable::create((int) self::toNumber($args[0]), 1, 1)->addMonths((int) self::toNumber($args[1]) - 1)->addDays((int) self::toNumber($args[2]) - 1),
            'YEAR' => self::toDate($args[0])->year,
            'MONTH' => self::toDate($args[0])->month,
            'DAY' => self::toDate($args[0])->day,
            'HOUR' => self::toDate($args[0])->hour,
            'MINUTE' => self::toDate($args[0])->minute,
            'WEEKDAY' => self::toDate($args[0])->dayOfWeekIso,
            'WEEKNUM' => self::toDate($args[0])->isoWeek(),
            'DAYS' => self::daysBetween(self::toDate($args[0]), self::toDate($args[1])),
            'ADD_DAYS' => self::shiftDate(self::toDate($args[0]), self::toNumber($args[1])),
            'SUBTRACT_DAYS' => self::shiftDate(self::toDate($args[0]), -self::toNumber($args[1])),
            'WORKDAYS' => $this->workdaysBetween(self::toDate($args[0]), self::toDate($args[1])),
            'HOURS_DIFF' => self::roundResult((self::wallSeconds(self::toDate($args[0])) - self::wallSeconds(self::toDate($args[1]))) / 3600),
            'MINUTES_DIFF' => self::roundResult((self::wallSeconds(self::toDate($args[0])) - self::wallSeconds(self::toDate($args[1]))) / 60),
            'FORMAT_DATE' => $this->formatDate(self::toDate($args[0]), array_key_exists(1, $args) ? $text(1) : 'YYYY-MM-DD'),
            default => throw new FormulaError('#NAME?', "{$name} is not a known function."),
        };
    }

    /**
     * Rounds half away from zero at `$digits` decimals.
     */
    private function scaledRound(float $number, float $digits, string $mode): float
    {
        $places = (int) $digits;
        $factor = 10 ** $places;
        $scaled = round(abs($number) * $factor, 9);
        $rounded = match ($mode) {
            'up' => ceil($scaled),
            'down' => floor($scaled),
            default => round($scaled, 0, PHP_ROUND_HALF_UP),
        };

        return ($number < 0 ? -1 : 1) * $rounded / $factor;
    }

    private function log(float $value, float $base): float
    {
        if ($value <= 0 || $base <= 0 || $base == 1) {
            throw new FormulaError('#NUM!', 'The logarithm is not defined for these numbers.');
        }

        return log($value) / log($base);
    }

    private function substitute(string $source, string $search, string $replacement, ?int $occurrence): string
    {
        if ($search === '') {
            return $source;
        }
        if ($occurrence === null) {
            return $this->clampText(str_replace($search, $replacement, $source));
        }

        $seen = 0;
        $offset = 0;
        while (($index = mb_strpos($source, $search, $offset)) !== false) {
            $seen++;
            if ($seen === $occurrence) {
                return $this->clampText(mb_substr($source, 0, $index).$replacement.mb_substr($source, $index + mb_strlen($search)));
            }
            $offset = $index + mb_strlen($search);
        }

        return $source;
    }

    private function find(string $search, string $text, int $start, bool $ignore_case): int
    {
        $haystack = $ignore_case ? mb_strtolower($text) : $text;
        $needle = $ignore_case ? mb_strtolower($search) : $search;
        $index = mb_strpos($haystack, $needle, max(1, $start) - 1);
        if ($index === false) {
            throw new FormulaError('#VALUE!', 'The text was not found.');
        }

        return $index + 1;
    }

    private function workdaysBetween(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $forward = $start->startOfDay()->lessThanOrEqualTo($end->startOfDay());
        [$from, $to] = $forward ? [$start->startOfDay(), $end->startOfDay()] : [$end->startOfDay(), $start->startOfDay()];
        $span = (int) floor(self::daysBetween($to, $from));
        if ($span > self::MAX_WORKDAY_SPAN) {
            throw new FormulaError('#NUM!', 'The two dates are too far apart.');
        }

        $count = 0;
        for ($offset = 0; $offset <= $span; $offset++) {
            if (! $from->addDays($offset)->isWeekend()) {
                $count++;
            }
        }

        return $forward ? $count : -$count;
    }

    private function formatDate(CarbonImmutable $date, string $pattern): string
    {
        $parts = [
            'YYYY' => $date->format('Y'),
            'YY' => $date->format('y'),
            'MMMM' => $date->format('F'),
            'MMM' => $date->format('M'),
            'MM' => $date->format('m'),
            'M' => $date->format('n'),
            'DD' => $date->format('d'),
            'D' => $date->format('j'),
            'dddd' => $date->format('l'),
            'ddd' => $date->format('D'),
            'HH' => $date->format('H'),
            'hh' => $date->format('h'),
            'mm' => $date->format('i'),
            'ss' => $date->format('s'),
            'A' => $date->format('A'),
        ];

        return preg_replace_callback('/YYYY|YY|MMMM|MMM|MM|M|DD|D|dddd|ddd|HH|hh|mm|ss|A/', fn (array $match) => $parts[$match[0]], $pattern) ?? $pattern;
    }
}
