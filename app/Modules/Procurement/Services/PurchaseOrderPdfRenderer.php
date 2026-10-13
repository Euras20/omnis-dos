<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\Party;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;

/**
 * DOS firm-format purchase order PDF.
 *
 * Layout mirrors frontend tplPreset('purchase_order', 'firm'):
 * centered title, meta strip, supplier + details, grid lines table,
 * totals, closing, signature, fine-print terms.
 *
 * Uses built-in Helvetica so the page is not blank (previous stream had
 * raw text without BT/Tf/Tj operators).
 *
 * Published document_template (doc_type=purchase_order) may override
 * title, closing, terms, signature, minRows when settings are present.
 */
final class PurchaseOrderPdfRenderer
{
    private const PO_TERMS = 'The BUYER and the SELLER agree that the Purchase Order is dematerialized and will be sent in an electronic format, which does not exclude the BUYER from using any other means of materialized transactions. The BUYER and the SELLER agree that the dematerialized Purchase Order and the dematerialized notification of acknowledgement of receipt of this Purchase Order consist of an electronic signature which has, between the BUYER and the SELLER, the same value than a handwritten signature which constitutes proof of the acceptance of a Purchase Order by the SELLER. Thus, the BUYER and the SELLER agree that computerized registries, kept in computer systems in reasonable security conditions, will be considered as proof of all electronic messages between the BUYER and the SELLER and, when necessary, payments made.';

    private const DEFAULT_CLOSING = 'The acceptance of our purchase order implies full agreement with our general purchase conditions (copy enclosed), and specific conditions as indicated below.';

    /** @var list<string> */
    private array $ops = [];

    public function render(PurchaseOrder $po, Company $company, ?Party $supplier = null): string
    {
        $this->ops = [];
        $settings = $this->templateSettings((string) $company->id);

        $title = strtoupper((string) ($settings['title'] ?? 'Purchase order'));
        $closing = (string) ($settings['closing'] ?? self::DEFAULT_CLOSING);
        $terms = (string) ($settings['terms'] ?? self::PO_TERMS);
        $signatureTpl = (string) ($settings['signature'] ?? 'SIGNED BY: {{approver}}');
        $signature = str_replace(
            '{{approver}}',
            $po->approved_by ? (string) $po->approved_by : '—',
            $signatureTpl
        );
        $minRows = max(1, (int) ($settings['minRows'] ?? 10));

        $currency = $po->currency ?: ((string) ($company->functional_currency ?? 'GHS'));
        $coName = (string) ($company->legal_name ?: 'DOS Limited');
        $coAddr = (string) ($company->address ?? '');
        $coTin = (string) ($company->tin ?? '');
        $coVat = (string) ($company->vat_number ?? '');

        $supplierName = $supplier?->name ?? '—';
        $supplierCode = $supplier?->code ?? '';
        $supplierAddr = trim(implode("\n", array_filter([
            $supplier?->address,
            $supplier?->city,
            $supplier?->region,
            $supplier?->country,
        ], static fn ($v) => $v !== null && $v !== '')));

        $orderDate = $po->order_date?->format('d/m/Y') ?? '';
        $expected = $po->expected_date?->format('d/m/Y') ?? '';

        $pageW = 595.0;
        $pageH = 842.0;
        $margin = 40.0;
        $y = $pageH - $margin;

        // Company block
        $this->text($margin, $y, $coName, 12, true);
        $y -= 14;
        foreach ($this->wrap($coAddr, 55) as $line) {
            $this->text($margin, $y, $line, 9);
            $y -= 11;
        }
        if ($coTin !== '' || $coVat !== '') {
            $this->text($margin, $y, trim("TIN {$coTin}  ·  VAT {$coVat}"), 8);
            $y -= 12;
        }

        // Centered title + PO number
        $y -= 8;
        $this->textCentered($pageW, $y, $title, 16, true);
        $y -= 18;
        $this->textCentered($pageW, $y, (string) $po->po_no, 11, true);
        $y -= 10;
        $this->hline($margin, $y, $pageW - $margin, 1.2);
        $y -= 16;

        // Meta strip
        $meta = [
            ['PROJECT NO.', '—'],
            ['ORDER NO.', (string) $po->po_no],
            ['REVISION NO.', '0-'.$orderDate],
            ['SUPPLIER ID', $supplierCode !== '' ? $supplierCode : (string) $po->supplier_id],
            ['ORDER DATE', $orderDate],
        ];
        $colW = ($pageW - 2 * $margin) / 5;
        foreach ($meta as $i => [$lab, $val]) {
            $x = $margin + $i * $colW;
            $this->text($x, $y, $lab, 7, true);
            $this->text($x, $y - 11, $this->clip((string) $val, 18), 9);
        }
        $y -= 28;
        $this->hline($margin, $y, $pageW - $margin, 1.0);
        $y -= 16;

        // Supplier + details
        $this->text($margin, $y, 'SUPPLIER', 8, true);
        $this->text($margin + 280, $y, 'ORDER DETAILS', 8, true);
        $y -= 12;
        $boxTop = $y;
        $this->text($margin, $y, $supplierName, 10, true);
        $y -= 12;
        foreach ($this->wrap($supplierAddr, 42) as $line) {
            $this->text($margin, $y, $line, 9);
            $y -= 11;
        }
        $leftBottom = $y;

        $rightY = $boxTop;
        foreach ([
            ['Status', strtoupper((string) $po->status)],
            ['Expected date', $expected !== '' ? $expected : '—'],
            ['Currency', $currency],
            ['Memo', $po->memo ? (string) $po->memo : '—'],
        ] as [$lab, $val]) {
            $this->text($margin + 280, $rightY, $lab.':', 8, true);
            $lines = $this->wrap((string) $val, 34);
            if ($lines === []) {
                $lines = ['—'];
            }
            foreach ($lines as $j => $line) {
                $this->text($margin + 350, $rightY - ($j * 11), $line, 9);
            }
            $rightY -= max(14, 11 * count($lines) + 3);
        }
        $y = min($leftBottom, $rightY) - 14;

        // Table header
        $headers = [
            ['REF.', 28],
            ['DESCRIPTION', 160],
            ['PART NO.', 70],
            ['UOM', 28],
            ['QTY', 40],
            ['UNIT PRICE', 70],
            ['AMOUNT', 70],
        ];
        $tableW = $pageW - 2 * $margin;
        $this->fillRect($margin, $y - 3, $tableW, 14, 0.18);
        $x = $margin + 2;
        foreach ($headers as [$lab, $w]) {
            $this->text($x, $y, $lab, 7, true, true);
            $x += $w;
        }
        $y -= 16;

        $lineRows = $po->lines->values();
        $rowCount = max($lineRows->count(), $minRows);
        for ($i = 0; $i < $rowCount; $i++) {
            if ($y < 170) {
                break;
            }
            /** @var PurchaseOrderLine|null $line */
            $line = $lineRows->get($i);
            $cells = $line ? [
                (string) $line->line_no,
                $this->clip((string) $line->description, 34),
                $line->item_id ? $this->clip((string) $line->item_id, 12) : '',
                strtoupper((string) ($line->unit ?? 'pcs')),
                $this->qty((string) $line->qty),
                $this->money((int) $line->unit_price_minor, $currency),
                $this->money((int) $line->net_minor, $currency),
            ] : ['', '', '', '', '', '', ''];

            if ($i % 2 === 0) {
                $this->fillRect($margin, $y - 2, $tableW, 12, 0.94);
            }
            $x = $margin + 2;
            foreach ($headers as $hi => [, $w]) {
                $this->text($x, $y, $cells[$hi], 8);
                $x += $w;
            }
            $y -= 13;
        }

        $y -= 6;
        $this->hline($margin, $y, $pageW - $margin, 0.8);
        $y -= 16;

        // Totals
        $totX = $pageW - $margin - 230;
        foreach ([
            ['TOTAL AMOUNT (EXCL. TAX)', $this->money((int) $po->net_minor, $currency), false],
            ['TAX AMOUNT', $this->money((int) $po->tax_total_minor, $currency), false],
            ['TOTAL AMOUNT (INCL TAX)', $this->money((int) $po->gross_minor, $currency), true],
        ] as [$lab, $val, $bold]) {
            $this->text($totX, $y, $lab, 8, $bold);
            $this->text($totX + 155, $y, $val, 9, $bold);
            $y -= 13;
        }

        $y -= 8;
        $this->hline($margin, $y, $pageW - $margin, 1.0);
        $y -= 14;

        foreach ($this->wrap($closing, 95) as $line) {
            if ($y < 100) {
                break;
            }
            $this->text($margin, $y, $line, 8);
            $y -= 10;
        }

        $y -= 6;
        $this->text($margin, $y, 'SIGNED BY', 8, true);
        $y -= 8;
        $this->strokeRect($margin + 40, $y - 28, 200, 32);
        $this->text($margin + 48, $y - 12, $signature, 8);
        $y -= 40;

        $this->hline($margin, $y, $pageW - $margin, 0.6);
        $y -= 12;
        foreach ($this->wrap($terms, 102) as $line) {
            if ($y < 48) {
                break;
            }
            $this->text($margin, $y, $line, 6);
            $y -= 8;
        }

        $this->textCentered($pageW, 36, 'Page 1 of 1', 8);

        return $this->buildPdf($pageW, $pageH);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateSettings(string $companyId): array
    {
        $tpl = DocumentTemplate::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'purchase_order')
            ->where('status', 'published')
            ->orderByDesc('version')
            ->first();

        return is_array($tpl?->settings) ? $tpl->settings : [];
    }

    private function money(int $minor, string $currency): string
    {
        return $currency.' '.number_format($minor / 100, 2, '.', ',');
    }

    private function qty(string $qty): string
    {
        $f = (float) $qty;
        if (abs($f - round($f)) < 0.0001) {
            return (string) (int) round($f);
        }

        return rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
    }

    private function clip(string $s, int $max): string
    {
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, max(0, $max - 1)).'…';
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $width): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }
        $out = [];
        foreach (explode("\n", $text) as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            while (strlen($para) > $width) {
                $chunk = substr($para, 0, $width);
                $break = strrpos($chunk, ' ');
                if ($break === false || $break < (int) ($width * 0.45)) {
                    $break = $width;
                }
                $out[] = trim(substr($para, 0, $break));
                $para = trim(substr($para, $break));
            }
            if ($para !== '') {
                $out[] = $para;
            }
        }

        return $out;
    }

    private function esc(string $s): string
    {
        $s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function text(float $x, float $y, string $text, int $size, bool $bold = false, bool $white = false): void
    {
        $font = $bold ? '/F2' : '/F1';
        $rgb = $white ? '1 1 1 rg' : '0 0 0 rg';
        $this->ops[] = sprintf(
            "BT\n%s\n%s %d Tf\n%.2f %.2f Td\n(%s) Tj\nET",
            $rgb,
            $font,
            $size,
            $x,
            $y,
            $this->esc($text)
        );
    }

    private function textCentered(float $pageW, float $y, string $text, int $size, bool $bold = false): void
    {
        // Approximate Helvetica width ~0.5em average
        $approx = strlen($text) * $size * 0.5;
        $x = max(40.0, ($pageW - $approx) / 2);
        $this->text($x, $y, $text, $size, $bold);
    }

    private function hline(float $x1, float $y, float $x2, float $w): void
    {
        $this->ops[] = sprintf("%.2f w %.2f %.2f m %.2f %.2f l S", $w, $x1, $y, $x2, $y);
    }

    private function fillRect(float $x, float $y, float $w, float $h, float $gray): void
    {
        $this->ops[] = sprintf("%.3f g %.2f %.2f %.2f %.2f re f 0 g", $gray, $x, $y, $w, $h);
    }

    private function strokeRect(float $x, float $y, float $w, float $h): void
    {
        $this->ops[] = sprintf("0.8 w %.2f %.2f %.2f %.2f re S", $x, $y, $w, $h);
    }

    private function buildPdf(float $pageW, float $pageH): string
    {
        $stream = implode("\n", $this->ops)."\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
                $pageW,
                $pageH
            ),
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$obj}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".count($offsets)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer<< /Size '.count($offsets)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
