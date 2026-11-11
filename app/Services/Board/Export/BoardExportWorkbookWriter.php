<?php

namespace App\Services\Board\Export;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Streams a board export to an .xlsx file with OpenSpout. Rows go straight
 * to disk as they are added, so memory stays flat whether the board has
 * fifty rows or five hundred thousand, unlike building the whole workbook in
 * memory first (what PhpSpreadsheet, and so Laravel Excel, does).
 *
 * Owns every visual decision of the file: the monday like title block,
 * group headers in the group's color, the gray column header row, colored
 * status pills, indented and collapsible subitem rows (an Excel outline),
 * frozen header rows and filters on the flat layout.
 */
class BoardExportWorkbookWriter
{
    /** Excel's own limit is 1,048,576 rows, a little headroom is kept for the header rows. */
    public const MAX_ROWS_PER_SHEET = 1_048_000;

    private const FONT_NAME = 'Calibri';

    private const TEXT_COLOR = '323338';

    private const MUTED_COLOR = '676879';

    private const HEADER_FILL = 'F5F6F8';

    private const BORDER_COLOR = 'D0D4E4';

    private const LINK_COLOR = '0073EA';

    private Writer $writer;

    private Sheet $sheet;

    private int $sheet_rows = 0;

    private int $sheet_count = 0;

    private string $sheet_base_name = 'Board';

    /** @var array<int, float> zero based column index => width */
    private array $column_widths = [];

    /** @var (callable(): void)|null re-writes the header rows on a continuation sheet */
    private $repeat_header = null;

    private ?int $filter_header_row = null;

    /** @var non-negative-int */
    private int $filter_last_column = 0;

    private Style $base_style;

    /** @var array<string, Style> */
    private array $style_cache = [];

    public function __construct(private readonly string $file_path, string $title, string $author)
    {
        $this->base_style = new Style(fontSize: 10, fontName: self::FONT_NAME, fontColor: self::TEXT_COLOR, cellVerticalAlignment: CellVerticalAlignment::CENTER);

        $this->writer = new Writer(new Options(
            FALLBACK_STYLE: $this->base_style,
            DEFAULT_ROW_HEIGHT: 20,
            properties: new Properties(title: $title, creator: $author, lastModifiedBy: $author),
        ));
        $this->writer->openToFile($this->file_path);
        $this->sheet = $this->writer->getCurrentSheet();
        $this->sheet_count = 1;
    }

    // ── Sheets ──────────────────────────────────────────────────────────

    /**
     * Names the current sheet (the first call) or opens a new one.
     *
     * @param  array<int, float>  $column_widths  zero based column index => width
     * @param  (callable(): void)|null  $repeat_header  writes the header again if the sheet fills up
     */
    public function startSheet(string $name, array $column_widths, int $freeze_rows = 0, ?string $freeze_column = null, ?callable $repeat_header = null): void
    {
        $this->closeSheetFilter();

        if ($this->sheet_rows > 0 || $this->sheet->getName() !== 'Sheet1') {
            $this->sheet = $this->writer->addNewSheetAndMakeItCurrent();
            $this->sheet_count++;
        }

        $this->sheet_base_name = $name;
        $this->sheet->setName($this->uniqueSheetName($name));
        $this->sheet_rows = 0;
        $this->column_widths = $column_widths;
        $this->repeat_header = $repeat_header;
        $this->applySheetLayout($freeze_rows, $freeze_column);
    }

    /**
     * Turns on Excel's filter buttons for the header row about to be written, see {@see writeHeaderRow()}.
     *
     * @param  non-negative-int  $last_column_index
     */
    public function enableFilterOnNextHeader(int $last_column_index): void
    {
        $this->filter_header_row = -1;
        $this->filter_last_column = $last_column_index;
    }

    // ── Rows ────────────────────────────────────────────────────────────

    /** The big board name and the "Exported by ..." line above it. */
    public function writeTitle(string $title, string $subtitle): void
    {
        $title_style = $this->namedStyle('title', fn () => $this->base_style->withFontSize(18)->withFontBold(true));
        $subtitle_style = $this->namedStyle('subtitle', fn () => $this->base_style->withFontColor(self::MUTED_COLOR)->withFontItalic(true));
        $this->addRow(new Row([new StringCell($title, $title_style)], 32));
        $this->addRow(new Row([new StringCell($subtitle, $subtitle_style)], 18));
        $this->writeSpacer();
    }

    /** A group's name in its own color, the way the board shows it. */
    public function writeGroupTitle(string $name, ?string $color, string $count_label): void
    {
        $color = ExportCell::excelColor($color) ?? '579BFC';
        $name_style = $this->namedStyle("group:{$color}", fn () => $this->base_style->withFontSize(13)->withFontBold(true)->withFontColor($color));
        $count_style = $this->namedStyle('muted', fn () => $this->base_style->withFontColor(self::MUTED_COLOR));
        $this->addRow(new Row([new StringCell($name, $name_style), new StringCell($count_label, $count_style)], 26));
    }

    /**
     * The gray column header row.
     *
     * @param  array<int, string>  $labels
     * @param  non-negative-int  $offset
     * @param  int<0, 7>  $outline_level
     */
    public function writeHeaderRow(array $labels, int $offset = 0, int $outline_level = 0, ?string $accent_color = null): void
    {
        $accent = ExportCell::excelColor($accent_color);
        $header_style = fn (bool $is_first) => $this->namedStyle('header:'.($is_first ? "first:{$accent}" : 'cell'), function () use ($is_first, $accent) {
            $style = $this->base_style
                ->withFontBold(true)
                ->withBackgroundColor(self::HEADER_FILL)
                ->withCellAlignment($is_first ? CellAlignment::LEFT : CellAlignment::CENTER)
                ->withBorder($this->cellBorder($is_first ? $accent : null));

            return $style;
        });

        $cells = [];
        foreach (array_values($labels) as $index => $label) {
            $cells[$offset + $index] = new StringCell($label, $header_style($index === 0));
        }

        $this->addRow(new Row($cells, 24, $outline_level));

        if ($this->filter_header_row === -1) {
            $this->filter_header_row = $this->sheet_rows;
        }
    }

    /**
     * One item (or subitem) row: the name cell, then one cell per column.
     *
     * @param  array<int, ExportCell>  $cells
     * @param  non-negative-int  $offset
     * @param  int<0, 7>  $outline_level
     */
    public function writeItemRow(array $cells, int $offset = 0, int $outline_level = 0, ?string $accent_color = null, bool $is_name_bold = false): void
    {
        $row_cells = [];
        foreach (array_values($cells) as $index => $cell) {
            $accent = $index === 0 ? ExportCell::excelColor($accent_color) : null;
            $row_cells[$offset + $index] = $this->toSpreadsheetCell($cell, $accent, $index === 0 && $is_name_bold);
        }

        $this->addRow(new Row($row_cells, Row::DEFAULT_HEIGHT, $outline_level));
    }

    /**
     * @param  int<0, 7>  $outline_level
     */
    public function writeSpacer(int $outline_level = 0): void
    {
        $this->addRow(new Row([], Row::DEFAULT_HEIGHT, $outline_level));
    }

    public function close(): void
    {
        $this->closeSheetFilter();
        $this->writer->close();
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function addRow(Row $row): void
    {
        if ($this->sheet_rows >= self::MAX_ROWS_PER_SHEET) {
            $this->continueOnNewSheet();
        }

        $this->writer->addRow($row);
        $this->sheet_rows++;
    }

    /** A sheet full to Excel's limit carries on in "Board (2)", "Board (3)", ... with the header repeated. */
    private function continueOnNewSheet(): void
    {
        $this->closeSheetFilter();
        $view = $this->sheet->getSheetView();
        $filter_last_column = $this->filter_last_column;
        $had_filter = $this->filter_header_row !== null;

        $this->sheet = $this->writer->addNewSheetAndMakeItCurrent();
        $this->sheet_count++;
        $this->sheet->setName($this->uniqueSheetName("{$this->sheet_base_name} ({$this->sheet_count})"));
        $this->sheet_rows = 0;
        $this->applyColumnWidths();
        if ($view !== null) {
            $this->sheet->setSheetView($view);
        }

        if ($this->repeat_header !== null) {
            if ($had_filter) {
                $this->enableFilterOnNextHeader($filter_last_column);
            }
            ($this->repeat_header)();
        }
    }

    private function applyColumnWidths(): void
    {
        foreach ($this->column_widths as $index => $width) {
            if ($index >= 0) {
                $this->sheet->setColumnWidth($width, $index + 1);
            }
        }
    }

    private function applySheetLayout(int $freeze_rows, ?string $freeze_column): void
    {
        $this->applyColumnWidths();

        $view = (new SheetView)->withShowGridLines(true)->withZoomScale(100);
        if ($freeze_rows > 0) {
            $view = $view->withFreezeRow($freeze_rows + 1);
        }
        if ($freeze_column !== null) {
            $view = $view->withFreezeColumn($freeze_column);
        }
        $this->sheet->setSheetView($view);
    }

    /** Applies the pending filter to the header row and every row written below it. */
    private function closeSheetFilter(): void
    {
        if ($this->filter_header_row !== null && $this->filter_header_row > 0 && $this->sheet_rows > $this->filter_header_row) {
            $this->sheet->setAutoFilter(new AutoFilter(0, $this->filter_header_row, $this->filter_last_column, $this->sheet_rows));
        }
        $this->filter_header_row = null;
    }

    /**
     * @param  non-empty-string|null  $accent_color
     */
    private function toSpreadsheetCell(ExportCell $cell, ?string $accent_color, bool $is_bold): Cell
    {
        $value = $cell->value;
        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }

        $is_link = $cell->hyperlink !== null && is_string($value) && $value !== '';
        $is_multiline = is_string($value) && str_contains($value, "\n");
        $style = $this->cellStyle($cell, $accent_color, $is_bold || $cell->bold, $is_link, $is_multiline);

        if ($value instanceof DateTimeInterface) {
            return new DateTimeCell($value, $style);
        }
        if (is_int($value) || is_float($value)) {
            return new NumericCell($value, $style);
        }
        if ($value === null || $value === '') {
            return new EmptyCell(null, $style);
        }

        return $is_link
            ? new StringCell((string) $value, $style, null, $cell->hyperlink)
            : new StringCell((string) $value, $style);
    }

    /**
     * One shared Style instance per distinct look. OpenSpout registers styles by object
     * identity, so building a new Style for every cell would register (and keep in memory)
     * one style per cell, and Excel refuses files with more than about 64,000 of them.
     *
     * @param  non-empty-string|null  $accent_color
     */
    private function cellStyle(ExportCell $cell, ?string $accent_color, bool $is_bold, bool $is_link, bool $is_multiline): Style
    {
        $key = implode('|', [$accent_color, $cell->format, $cell->fill, $cell->font_color, $is_bold, $cell->centered, $is_link, $is_multiline]);

        return $this->style_cache[$key] ??= (function () use ($cell, $accent_color, $is_bold, $is_link, $is_multiline) {
            $style = $this->base_style->withBorder($this->cellBorder($accent_color));
            if ($cell->format !== null) {
                $style = $style->withFormat($cell->format);
            }
            if ($cell->fill !== null) {
                $style = $style->withBackgroundColor($cell->fill);
            }
            if ($cell->font_color !== null) {
                $style = $style->withFontColor($cell->font_color);
            }
            if ($is_bold) {
                $style = $style->withFontBold(true);
            }
            if ($cell->centered) {
                $style = $style->withCellAlignment(CellAlignment::CENTER);
            }
            if ($is_link) {
                $style = $style->withFontColor(self::LINK_COLOR)->withFontUnderline(true);
            }
            if ($is_multiline) {
                $style = $style->withShouldWrapText(true)->withCellVerticalAlignment(CellVerticalAlignment::TOP);
            }

            return $style;
        })();
    }

    /** A shared Style for the fixed looks (title, header, group name), see {@see cellStyle()}. */
    private function namedStyle(string $key, callable $build): Style
    {
        return $this->style_cache["named:{$key}"] ??= $build();
    }

    /** A thin light border on every side, with a thick colored left edge for the name cell, like the board's group stripe. */
    /**
     * @param  non-empty-string|null  $accent_color
     */
    private function cellBorder(?string $accent_color = null): Border
    {
        $parts = [
            new BorderPart(BorderName::TOP, self::BORDER_COLOR, BorderWidth::THIN, BorderStyle::SOLID),
            new BorderPart(BorderName::RIGHT, self::BORDER_COLOR, BorderWidth::THIN, BorderStyle::SOLID),
            new BorderPart(BorderName::BOTTOM, self::BORDER_COLOR, BorderWidth::THIN, BorderStyle::SOLID),
            $accent_color !== null
                ? new BorderPart(BorderName::LEFT, $accent_color, BorderWidth::THICK, BorderStyle::SOLID)
                : new BorderPart(BorderName::LEFT, self::BORDER_COLOR, BorderWidth::THIN, BorderStyle::SOLID),
        ];

        return new Border(...$parts);
    }

    /** Excel sheet names: at most 31 characters, none of `[]:*?/\`, unique in the workbook. */
    private function uniqueSheetName(string $name): string
    {
        $clean = trim(preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name) ?? '') ?: 'Board';
        $clean = trim($clean, "'");
        $candidate = mb_substr($clean, 0, 31);

        $taken = array_map(fn (Sheet $sheet) => mb_strtolower($sheet->getName()), array_filter(
            $this->writer->getSheets(),
            fn (Sheet $sheet) => $sheet !== $this->sheet,
        ));

        $suffix = 2;
        while (in_array(mb_strtolower($candidate), $taken, true)) {
            $tail = " ({$suffix})";
            $candidate = mb_substr($clean, 0, 31 - mb_strlen($tail)).$tail;
            $suffix++;
        }

        return $candidate ?: 'Board';
    }
}
