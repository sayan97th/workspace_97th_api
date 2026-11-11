<?php

namespace App\Services\Board\Export;

use DateTimeInterface;

/**
 * One formatted cell of a board export, independent of the spreadsheet
 * library: what to write (`value`), how Excel should show it (`format`, a
 * number format code) and how it should look (`fill`, `font_color`, ...).
 * {@see BoardExportWorkbookWriter} turns it into an OpenSpout cell.
 */
final readonly class ExportCell
{
    /** Excel refuses longer text in a single cell. */
    public const MAX_TEXT_LENGTH = 32767;

    public function __construct(
        public string|int|float|bool|DateTimeInterface|null $value,
        public ?string $format = null,
        /** @var non-empty-string|null */
        public ?string $fill = null,
        /** @var non-empty-string|null */
        public ?string $font_color = null,
        public bool $bold = false,
        public bool $centered = false,
        public ?string $hyperlink = null,
    ) {}

    public static function text(string $value, ?string $hyperlink = null): self
    {
        return new self(self::clip($value), hyperlink: $hyperlink !== null && $hyperlink !== '' ? $hyperlink : null);
    }

    public static function number(int|float $value, ?string $format = null): self
    {
        return new self($value, format: $format);
    }

    public static function date(DateTimeInterface $value, string $format): self
    {
        return new self($value, format: $format);
    }

    /**
     * A colored pill like the Status, Label and color picker cells: the option's color as the
     * fill, white or dark text depending on which reads better on it.
     */
    public static function pill(string $label, ?string $hex_color): self
    {
        $fill = self::excelColor($hex_color);

        return new self(
            self::clip($label),
            fill: $fill,
            font_color: $fill !== null ? self::readableTextColor($fill) : null,
            bold: $fill !== null,
            centered: true,
        );
    }

    public static function empty(): self
    {
        return new self(null);
    }

    /**
     * `#fdab3d` (or `fdab3d`) as OpenSpout's `FDAB3D`, null when it isn't a hex color.
     *
     * @return non-empty-string|null
     */
    public static function excelColor(?string $hex_color): ?string
    {
        if ($hex_color === null) {
            return null;
        }

        $hex = ltrim(trim($hex_color), '#');
        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return strlen($hex) === 6 && ctype_xdigit($hex) ? strtoupper($hex) : null;
    }

    /**
     * White on dark fills, near black on light ones, by the fill's perceived brightness.
     *
     * @return non-empty-string
     */
    public static function readableTextColor(string $excel_color): string
    {
        $red = hexdec(substr($excel_color, 0, 2));
        $green = hexdec(substr($excel_color, 2, 2));
        $blue = hexdec(substr($excel_color, 4, 2));
        $brightness = ($red * 299 + $green * 587 + $blue * 114) / 1000;

        return $brightness > 186 ? '323338' : 'FFFFFF';
    }

    private static function clip(string $value): string
    {
        return mb_strlen($value) > self::MAX_TEXT_LENGTH ? mb_substr($value, 0, self::MAX_TEXT_LENGTH - 1).'…' : $value;
    }
}
