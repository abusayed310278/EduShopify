<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV reading and writing for the admin import / export screens, built for
 * files that go through Excel and back.
 *
 * Reading copes with what Excel produces: a UTF-8 byte-order mark, UTF-16
 * ("Unicode text"), Windows-1252 ("CSV"), and semicolon or tab separators.
 * Writing adds the byte-order mark Excel needs to show UTF-8 correctly, and
 * neutralises cells Excel would run as a formula (they start with = + - @);
 * cell() undoes that on the way back in.
 */
class CsvFile
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @return array{header: list<string>, lines: list<array{0: int, 1: array}>}
     *                                     lower-cased header + [line number, cells] per row
     */
    public static function read(UploadedFile $file): array
    {
        $content = @file_get_contents($file->getRealPath());
        if ($content === false) {
            throw ValidationException::withMessages(['file' => 'Could not read the uploaded file.']);
        }

        $content = self::toUtf8($content);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $delimiter = self::detectDelimiter($content);
        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if (! $header) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $lines = [];
        $lineNumber = 1;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $lines[] = [++$lineNumber, $cells];
        }
        fclose($handle);

        return [
            'header' => array_map(fn ($h) => strtolower(trim((string) $h)), $header),
            'lines' => $lines,
        ];
    }

    /** A cell's clean text; $column is the array_search() result for its header (false = no such column). */
    public static function cell(array $cells, int|false $column): string
    {
        if ($column === false) {
            return '';
        }

        $value = trim((string) ($cells[$column] ?? ''));

        return preg_match('/^\'[=+\-@]/', $value) ? substr($value, 1) : $value;
    }

    /** @param  iterable<array>  $rows  one list of values per row, in $columns order */
    public static function download(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, self::BOM);
            fputcsv($out, $columns, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::exportValue(...), $row), ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function exportValue(mixed $value): string
    {
        $value = match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => (string) $value,
        };

        return preg_match('/^[=+\-@\t\r]/', $value) && ! is_numeric($value) ? "'".$value : $value;
    }

    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE")) {
            return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($content, "\xFE\xFF")) {
            return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, 3);
        }

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /** The separator used most in the header line (comma on a tie). */
    private static function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\r\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
