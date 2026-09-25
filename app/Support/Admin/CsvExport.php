<?php

namespace App\Support\Admin;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV download row by row, so exporting thousands of users or boards never holds
 * the whole file in memory. Starts with a UTF-8 BOM so Excel opens accented names correctly.
 */
class CsvExport
{
    /**
     * @param  array<int, string>  $headings
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    public static function download(string $file_name, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headings, escape: '\\');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($value) => self::sanitize($value), $row), escape: '\\');
            }

            fclose($handle);
        }, $file_name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Neutralizes spreadsheet formula injection: a cell a user controls (a name, a board
     * label) starting with `=`, `+`, `-` or `@` would otherwise run as a formula in Excel.
     */
    private static function sanitize(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'{$text}" : $text;
    }
}
