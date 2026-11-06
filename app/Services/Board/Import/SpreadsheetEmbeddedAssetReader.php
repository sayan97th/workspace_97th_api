<?php

namespace App\Services\Board\Import;

use GdImage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\BaseDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

/**
 * Finds the files a spreadsheet carries inside itself and the cell each one
 * belongs to, so the "Import items" wizard can bring them into a Files column
 * instead of dropping them:
 *
 * - floating pictures (screenshots pasted over a cell), anchored to the cell
 *   their top left corner visually sits in, see {@see normalizeAnchor()};
 * - Excel's "Place in cell" pictures, stored as rich values (`vm` attribute);
 * - embedded documents (Insert > Object), either stored as the file itself
 *   (a .docx/.xlsx/.pptx/.pdf part) or wrapped in an OLE package.
 *
 * Only the file types a Files cell accepts are kept, see
 * {@see self::ALLOWED_EXTENSIONS}. Everything else (EMF previews of an OLE
 * object, charts, shapes) is ignored. Reading never fails an import: a part
 * that can't be read is skipped.
 */
class SpreadsheetEmbeddedAssetReader
{
    /** Mirrors `StoreBoardItemCellFileRequest`'s accepted file types. */
    public const ALLOWED_EXTENSIONS = ['pdf', 'xlsx', 'xls', 'csv', 'docx', 'doc', 'pptx', 'ppt', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** One embedded file larger than this is left out, same limit as a Files cell upload. */
    public const MAX_ASSET_BYTES = 50 * 1024 * 1024;

    /** Defensive cap on how many embedded files one sheet may bring in. */
    public const MAX_ASSETS = 300;

    private const MIME_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * Every embedded file of `$sheet`, keyed by the cell it belongs to ("G8").
     *
     * @param  string  $file_path  the uploaded workbook on disk, read again as a zip for the parts PhpSpreadsheet doesn't expose
     * @param  int  $sheet_index  the sheet's position in the workbook
     * @return array<string, array<int, array{file_name: string, mime_type: string, kind: string, contents: string}>>
     */
    public function read(Worksheet $sheet, string $file_path, int $sheet_index): array
    {
        $found = [];

        foreach ($sheet->getDrawingCollection() as $drawing) {
            $found[] = [$this->normalizeAnchor($sheet, $drawing), $this->readDrawing($drawing)];
        }

        $zip = $this->openZip($file_path);

        if ($zip !== null) {
            try {
                $sheet_path = $this->sheetPartPath($zip, $sheet_index);

                if ($sheet_path !== null) {
                    array_push($found, ...$this->readInCellPictures($zip, $sheet_path), ...$this->readEmbeddedObjects($zip, $sheet_path));
                }
            } catch (Throwable) {
                // A malformed part only loses its own files, never the import.
            } finally {
                $zip->close();
            }
        }

        $assets = [];

        foreach (array_slice(array_values(array_filter($found, fn (array $entry) => $entry[1] !== null)), 0, self::MAX_ASSETS) as [$coordinate, $asset]) {
            $assets[$coordinate][] = $asset;
        }

        return $assets;
    }

    /**
     * The cell a picture visually starts in. Spreadsheet apps often anchor a
     * picture to one cell with an offset as wide or tall as that cell (Google
     * Sheets does this for a picture dropped at the top of the next row), so
     * the offset is walked across the column widths and row heights until it
     * fits inside a cell.
     */
    private function normalizeAnchor(Worksheet $sheet, BaseDrawing $drawing): string
    {
        [$column, $row] = Coordinate::coordinateFromString($drawing->getCoordinates());
        $column_index = Coordinate::columnIndexFromString($column);
        $row = (int) $row;
        $offset_x = $drawing->getOffsetX();
        $offset_y = $drawing->getOffsetY();

        for ($guard = 0; $guard < 50; $guard++) {
            $width = $this->columnWidthInPixels($sheet, $column_index);
            if ($width <= 0 || $offset_x < $width - 1) {
                break;
            }
            $offset_x -= $width;
            $column_index++;
        }

        for ($guard = 0; $guard < 50; $guard++) {
            $height = $this->rowHeightInPixels($sheet, $row);
            if ($height <= 0 || $offset_y < $height - 1) {
                break;
            }
            $offset_y -= $height;
            $row++;
        }

        return Coordinate::stringFromColumnIndex($column_index).$row;
    }

    private function columnWidthInPixels(Worksheet $sheet, int $column_index): int
    {
        $width = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column_index))->getWidth();

        if ($width < 0) {
            $width = $sheet->getDefaultColumnDimension()->getWidth();
        }

        // Excel's own character width to pixel rule for its default font.
        return (int) floor(($width < 0 ? 8.43 : $width) * 7 + 5);
    }

    private function rowHeightInPixels(Worksheet $sheet, int $row): int
    {
        $height = $sheet->getRowDimension($row)->getRowHeight();

        if ($height < 0) {
            $height = $sheet->getDefaultRowDimension()->getRowHeight();
        }

        return (int) round(($height < 0 ? 15 : $height) * 96 / 72);
    }

    /**
     * @return array{file_name: string, mime_type: string, kind: string, contents: string}|null
     */
    private function readDrawing(BaseDrawing $drawing): ?array
    {
        try {
            if ($drawing instanceof Drawing) {
                $path = $drawing->getPath();
                $extension = strtolower($drawing->getExtension() ?: pathinfo($path, PATHINFO_EXTENSION));
                $contents = $path !== '' ? @file_get_contents($path) : false;

                return is_string($contents) ? $this->buildAsset($this->drawingFileName($drawing, $extension), $extension, $contents) : null;
            }

            if ($drawing instanceof MemoryDrawing) {
                $resource = $drawing->getImageResource();

                if (! $resource instanceof GdImage) {
                    return null;
                }

                ob_start();
                imagepng($resource);
                $contents = (string) ob_get_clean();

                return $this->buildAsset($this->drawingFileName($drawing, 'png'), 'png', $contents);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function drawingFileName(BaseDrawing $drawing, string $extension): string
    {
        $name = trim($drawing->getName());

        if ($name === '' || preg_match('/^(image|picture|imagen)\s*\d*(\.\w+)?$/i', $name) === 1) {
            $name = 'image';
        }

        return pathinfo($name, PATHINFO_EXTENSION) === '' ? "{$name}.{$extension}" : $name;
    }

    /**
     * Excel's "Place in cell" pictures: the cell carries a `vm` index into
     * `xl/metadata.xml`, which points at a rich value whose first field is
     * the index of the picture's relationship in `xl/richData/richValueRel.xml`.
     *
     * @return array<int, array{0: string, 1: array{file_name: string, mime_type: string, kind: string, contents: string}|null}>
     */
    private function readInCellPictures(ZipArchive $zip, string $sheet_path): array
    {
        $sheet_xml = $zip->getFromName($sheet_path);

        if (! is_string($sheet_xml) || ! str_contains($sheet_xml, ' vm="')) {
            return [];
        }

        $rich_values = $zip->getFromName('xl/richData/rdrichvalue.xml');
        $value_rels = $zip->getFromName('xl/richData/richValueRel.xml');
        $value_rel_targets = $this->relationshipTargets($zip, 'xl/richData/_rels/richValueRel.xml.rels', 'xl/richData');

        if (! is_string($rich_values) || ! is_string($value_rels)) {
            return [];
        }

        preg_match_all('/<rv\b[^>]*>(.*?)<\/rv>/s', $rich_values, $rich_value_matches);
        preg_match_all('/<rel\b[^>]*r:id="([^"]+)"/', $value_rels, $rel_matches);
        $metadata_targets = $this->valueMetadataToRichValue($zip);

        $pictures = [];

        preg_match_all('/<c\b[^>]*\br="([A-Z]+\d+)"[^>]*\bvm="(\d+)"/', $sheet_xml, $cells, PREG_SET_ORDER);

        foreach ($cells as [, $coordinate, $vm]) {
            $rich_value_index = $metadata_targets[(int) $vm - 1] ?? ((int) $vm - 1);
            $rich_value = $rich_value_matches[1][$rich_value_index] ?? null;

            if ($rich_value === null || preg_match('/<v[^>]*>(\d+)<\/v>/', $rich_value, $first_value) !== 1) {
                continue;
            }

            $rel_id = $rel_matches[1][(int) $first_value[1]] ?? null;
            $target = $rel_id !== null ? ($value_rel_targets[$rel_id] ?? null) : null;

            if ($target === null) {
                continue;
            }

            $contents = $zip->getFromName($target);
            $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));

            if (is_string($contents)) {
                $pictures[] = [$coordinate, $this->buildAsset("image.{$extension}", $extension, $contents)];
            }
        }

        return $pictures;
    }

    /**
     * `vm` (1-based) => rich value index, through `xl/metadata.xml`'s value
     * metadata blocks and its `XLRICHVALUE` future metadata. Empty when the
     * workbook has no metadata part, the caller then assumes `vm - 1`.
     *
     * @return array<int, int>
     */
    private function valueMetadataToRichValue(ZipArchive $zip): array
    {
        $metadata = $zip->getFromName('xl/metadata.xml');

        if (! is_string($metadata)) {
            return [];
        }

        preg_match_all('/<xlrd:rvb\b[^>]*\bi="(\d+)"/', $metadata, $rvb_matches);

        if (preg_match('/<valueMetadata\b[^>]*>(.*?)<\/valueMetadata>/s', $metadata, $value_metadata) !== 1) {
            return [];
        }

        preg_match_all('/<bk>\s*<rc\b[^>]*\bv="(\d+)"/', $value_metadata[1], $blocks);

        $map = [];
        foreach ($blocks[1] as $index => $future_index) {
            $rich_value_index = $rvb_matches[1][(int) $future_index] ?? null;
            if ($rich_value_index !== null) {
                $map[$index] = (int) $rich_value_index;
            }
        }

        return $map;
    }

    /**
     * Insert > Object documents. Office files and PDFs saved "as is" sit in
     * `xl/embeddings/` under their own extension; anything else is an OLE
     * package (`.bin`) the original file is unwrapped from.
     *
     * @return array<int, array{0: string, 1: array{file_name: string, mime_type: string, kind: string, contents: string}|null}>
     */
    private function readEmbeddedObjects(ZipArchive $zip, string $sheet_path): array
    {
        $sheet_xml = $zip->getFromName($sheet_path);

        if (! is_string($sheet_xml) || ! str_contains($sheet_xml, '<oleObject')) {
            return [];
        }

        $rels_path = dirname($sheet_path).'/_rels/'.basename($sheet_path).'.rels';
        $targets = $this->relationshipTargets($zip, $rels_path, dirname($sheet_path));
        $objects = [];

        preg_match_all('/<oleObject\b([^>]*?)(?:\/>|>(.*?)<\/oleObject>)/s', $sheet_xml, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attributes = $match[1];
            $body = $match[2] ?? '';

            if (preg_match('/r:id="([^"]+)"/', $attributes, $rel) !== 1 || ! isset($targets[$rel[1]])) {
                continue;
            }

            // Only objects that carry their own anchor can be placed in a cell.
            if (preg_match('/<(?:xdr:)?from>\s*<(?:xdr:)?col>(\d+)<\/(?:xdr:)?col>.*?<(?:xdr:)?row>(\d+)<\/(?:xdr:)?row>/s', $body, $anchor) !== 1) {
                continue;
            }

            $coordinate = Coordinate::stringFromColumnIndex((int) $anchor[1] + 1).((int) $anchor[2] + 1);
            $target = $targets[$rel[1]];
            $contents = $zip->getFromName($target);

            if (! is_string($contents)) {
                continue;
            }

            $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $asset = $extension === 'bin'
                ? $this->unwrapOlePackage($contents)
                : $this->buildAsset($this->embeddedFileName(basename($target)), $extension, $contents);

            $objects[] = [$coordinate, $asset];
        }

        return $objects;
    }

    /** "Microsoft_Word_Document1.docx" reads better as "Word document 1.docx". */
    private function embeddedFileName(string $base_name): string
    {
        $readable = (string) preg_replace(['/^Microsoft_/', '/_/'], ['', ' '], $base_name);

        return $readable !== '' ? $readable : $base_name;
    }

    /**
     * An OLE package (`\x01Ole10Native` stream) holds the original file name
     * and bytes: a size, flags, the label, the source path, then the data. A
     * PDF found anywhere in the package is the fallback when that header
     * can't be read.
     *
     * @return array{file_name: string, mime_type: string, kind: string, contents: string}|null
     */
    private function unwrapOlePackage(string $binary): ?array
    {
        if (preg_match('/\x02\x00([^\x00]{1,260})\x00([^\x00]{1,260})\x00\x00\x00\x03\x00(.{4})/s', $binary, $header, PREG_OFFSET_CAPTURE) === 1) {
            $label = $header[1][0];
            $temp_path_length = unpack('V', $header[3][0])[1] ?? 0;
            $offset = $header[3][1] + 4 + $temp_path_length;
            $data_length = unpack('V', substr($binary, $offset, 4))[1] ?? 0;
            $data = substr($binary, $offset + 4, $data_length);
            $extension = strtolower(pathinfo($label, PATHINFO_EXTENSION));

            if ($data_length > 0 && strlen($data) === $data_length && $extension !== '') {
                return $this->buildAsset(basename(str_replace('\\', '/', $label)), $extension, $data);
            }
        }

        $start = strpos($binary, '%PDF-');
        $end = strrpos($binary, '%%EOF');

        if ($start !== false && $end !== false && $end > $start) {
            return $this->buildAsset('document.pdf', 'pdf', substr($binary, $start, $end - $start + 5));
        }

        return null;
    }

    /**
     * @return array{file_name: string, mime_type: string, kind: string, contents: string}|null
     */
    private function buildAsset(string $file_name, string $extension, string $contents): ?array
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true) || $contents === '' || strlen($contents) > self::MAX_ASSET_BYTES) {
            return null;
        }

        $mime_type = self::MIME_TYPES[$extension];

        return [
            'file_name' => mb_substr($file_name, 0, 255),
            'mime_type' => $mime_type,
            'kind' => str_starts_with($mime_type, 'image/') ? 'image' : 'file',
            'contents' => $contents,
        ];
    }

    private function openZip(string $file_path): ?ZipArchive
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive;

        return $zip->open($file_path, ZipArchive::RDONLY) === true ? $zip : null;
    }

    /**
     * The zip path of the `$sheet_index`th sheet, through the workbook's own
     * sheet order and relationships.
     */
    private function sheetPartPath(ZipArchive $zip, int $sheet_index): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');

        if (! is_string($workbook)) {
            return null;
        }

        preg_match_all('/<sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheets);
        $rel_id = $sheets[1][$sheet_index] ?? null;

        return $rel_id !== null ? ($this->relationshipTargets($zip, 'xl/_rels/workbook.xml.rels', 'xl')[$rel_id] ?? null) : null;
    }

    /**
     * Relationship id => target part path, resolved against `$base_directory`.
     *
     * @return array<string, string>
     */
    private function relationshipTargets(ZipArchive $zip, string $rels_path, string $base_directory): array
    {
        $rels = $zip->getFromName($rels_path);

        if (! is_string($rels)) {
            return [];
        }

        preg_match_all('/<Relationship\b([^>]*)\/?>/', $rels, $matches);
        $targets = [];

        foreach ($matches[1] as $attributes) {
            if (preg_match('/\bId="([^"]+)"/', $attributes, $id) !== 1 || preg_match('/\bTarget="([^"]+)"/', $attributes, $target) !== 1) {
                continue;
            }

            if (str_contains($attributes, 'TargetMode="External"')) {
                continue;
            }

            $targets[$id[1]] = $this->resolvePartPath($base_directory, html_entity_decode($target[1]));
        }

        return $targets;
    }

    private function resolvePartPath(string $base_directory, string $target): string
    {
        $path = str_starts_with($target, '/') ? ltrim($target, '/') : $base_directory.'/'.$target;
        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '.' && $segment !== '') {
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }
}
