<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

/**
 * Single-page A4 PDF that follows the quotation/invoice sheet:
 * teal bars, header, line table, totals, notes. No external library.
 */
final class SheetPdf
{
    /**
     * @param  array{
     *   title: string,
     *   company: list<string>,
     *   customer: list<string>,
     *   meta: list<string>,
     *   job: string,
     *   terms: string,
     *   expiry: string,
     *   rows: list<array{no: string, part: string, description: string, qty: string, unit: string, total: string}>,
     *   totals: list<array{label: string, value: string, emphasis?: bool}>,
     *   notes: list<string>,
     *   footer: string
     * }  $sheet
     */
    public static function render(array $sheet): string
    {
        $ops = '';
        $ops .= self::rect(0, 800, 595, 42, '0.01 0.42 0.55');
        $ops .= self::text(40, 814, 18, '1 1 1', $sheet['title']);

        $y = 780;
        foreach ($sheet['company'] as $i => $line) {
            $ops .= self::text(40, $y, $i === 0 ? 10 : 9, '0 0 0', $line);
            $y -= 12;
        }
        $y -= 6;
        foreach ($sheet['customer'] as $i => $line) {
            $ops .= self::text(40, $y, $i === 0 ? 10 : 9, '0.1 0.1 0.1', $line);
            $y -= 12;
        }

        $my = 780;
        foreach ($sheet['meta'] as $line) {
            $ops .= self::text(360, $my, 9, '0 0 0', $line);
            $my -= 13;
        }

        $ops .= self::rect(36, 690, 523, 16, '0.01 0.42 0.55');
        $ops .= self::text(42, 694, 8, '1 1 1', 'Job');
        $ops .= self::text(280, 694, 8, '1 1 1', 'Payment Terms');
        $ops .= self::text(430, 694, 8, '1 1 1', 'Expiry');
        $ops .= self::text(42, 676, 8, '0 0 0', $sheet['job']);
        $ops .= self::text(280, 676, 8, '0 0 0', $sheet['terms']);
        $ops .= self::text(430, 676, 8, '0 0 0', $sheet['expiry']);

        $ops .= self::rect(36, 648, 523, 14, '0.01 0.42 0.55');
        foreach (['#', 'Part No.', 'Description', 'Qty', 'Unit', 'Total'] as $i => $h) {
            $ops .= self::text([42, 62, 140, 400, 440, 500][$i], 652, 8, '1 1 1', $h);
        }

        $y = 634;
        foreach ($sheet['rows'] as $n => $row) {
            if ($n % 2 === 1) {
                $ops .= self::rect(36, $y - 3, 523, 14, '0.78 0.94 0.99');
            }
            $ops .= self::text(42, $y, 8, '0 0 0', $row['no']);
            $ops .= self::text(62, $y, 8, '0 0 0', self::clip($row['part'], 16));
            $ops .= self::text(140, $y, 8, '0 0 0', self::clip($row['description'], 42));
            $ops .= self::text(400, $y, 8, '0 0 0', $row['qty']);
            $ops .= self::text(440, $y, 8, '0 0 0', self::clip($row['unit'], 12));
            $ops .= self::text(500, $y, 8, '0 0 0', $row['total']);
            $y -= 14;
            if ($y < 160) {
                break;
            }
        }

        $ops .= self::rect(36, $y - 4, 523, 6, '0.59 0.89 0.97');
        $y -= 22;
        foreach ($sheet['totals'] as $total) {
            $ops .= self::text(360, $y, 9, '0 0 0', $total['label']);
            $ops .= self::text(480, $y, 9, '0 0 0', $total['value']);
            if (! empty($total['emphasis'])) {
                $ops .= self::rect(470, $y - 3, 90, 14, '0.21 0.71 0.89');
                $ops .= self::text(480, $y, 9, '1 1 1', $total['value']);
            }
            $y -= 14;
        }

        $y -= 8;
        $ops .= self::text(40, $y, 9, '0.01 0.36 0.48', 'NOTES');
        $y -= 12;
        foreach ($sheet['notes'] as $note) {
            $ops .= self::text(40, $y, 8, '0 0 0', self::clip($note, 90));
            $y -= 11;
        }
        $ops .= self::text(40, 40, 8, '0.2 0.2 0.2', self::clip($sheet['footer'], 90));

        return self::wrap($ops);
    }

    private static function rect(int $x, int $y, int $w, int $h, string $rgb): string
    {
        return "{$rgb} rg\n{$x} {$y} {$w} {$h} re f\n";
    }

    private static function text(int $x, int $y, int $size, string $rgb, string $value): string
    {
        return "BT\n{$rgb} rg\n/F1 {$size} Tf\n{$x} {$y} Td\n(".self::escape($value).") Tj\nET\n";
    }

    private static function clip(string $value, int $max): string
    {
        $value = trim($value);

        return strlen($value) > $max ? substr($value, 0, $max - 1).'…' : $value;
    }

    private static function escape(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }

    private static function wrap(string $content): string
    {
        $objects = [
            "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n",
            "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n",
            "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>endobj\n",
            '4 0 obj<< /Length '.strlen($content)." >>\nstream\n{$content}endstream\nendobj\n",
            "5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($offsets))."\n0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer<< /Size ".count($offsets)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
