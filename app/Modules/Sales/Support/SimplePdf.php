<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

/**
 * Minimal single-page PDF (Helvetica) for a quotation email attachment.
 * No external library. Enough for the customer to open the quote.
 */
final class SimplePdf
{
    /**
     * @param  list<string>  $lines
     */
    public static function fromLines(string $title, array $lines): string
    {
        $content = "BT\n/F1 16 Tf\n50 760 Td\n(".self::escape($title).") Tj\n";
        $content .= "/F1 10 Tf\n0 -24 Td\n";
        foreach ($lines as $line) {
            $content .= '('.self::escape($line).") Tj\n0 -14 Td\n";
        }
        $content .= "ET\n";

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
        $pdf .= "xref\n0 ".(count($offsets))."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer<< /Size ".count($offsets)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    private static function escape(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }
}
