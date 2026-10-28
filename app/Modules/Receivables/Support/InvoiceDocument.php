<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Support;

use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Invoice;
use App\Models\Party;
use App\Modules\Sales\Support\SheetPdf;

/**
 * Invoice letterhead for the customer email and the attached PDF.
 */
final class InvoiceDocument
{
    /**
     * @param  \Illuminate\Support\Collection<int, CompanyBankAccount>  $banks
     * @return array{html: string, pdf: string}
     */
    public static function build(Invoice $invoice, ?Party $customer, Company $company, $banks): array
    {
        $currency = (string) ($invoice->currency ?: 'GHS');
        $money = static fn (int $minor): string => number_format($minor / 100, 2);
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $rows = '';
        $pdfRows = [];
        foreach ($invoice->lines as $i => $line) {
            $part = $line->item?->sku ?: '—';
            $bg = $i % 2 === 1 ? 'background:#c7effc;' : '';
            $rows .= '<tr style="'.$bg.'vertical-align:top;">'
                .'<td style="padding:8px 4px;text-align:center;">'.(int) $line->line_no.'</td>'
                .'<td style="padding:8px 6px;font-weight:700;">'.$e($part).'</td>'
                .'<td style="padding:8px 6px;">'.$e($line->description).'</td>'
                .'<td style="padding:8px 4px;text-align:center;font-weight:600;">'.$e((string) $line->qty).'</td>'
                .'<td style="padding:8px 6px;text-align:right;">'.$currency.' '.$money((int) $line->unit_price_minor).'</td>'
                .'<td style="padding:8px 6px;text-align:right;">'.$currency.' '.$money((int) $line->net_minor).'</td>'
                .'</tr>';
            $pdfRows[] = [
                'no' => (string) $line->line_no,
                'part' => $part,
                'description' => (string) $line->description,
                'qty' => (string) $line->qty,
                'unit' => $currency.' '.$money((int) $line->unit_price_minor),
                'total' => $currency.' '.$money((int) $line->net_minor),
            ];
        }

        $bank = $banks->first();
        $bankLine = $bank
            ? 'Payments via bank transfer to <strong>'.$e($bank->account_name).'</strong> Account No: <strong>'.$e($bank->account_number).'</strong>, '.$e($bank->bank_name).($bank->swift ? ', Swift: <strong>'.$e($bank->swift).'</strong>' : '')
            : 'Bank details on request.';

        $companyHtml = '';
        foreach (array_filter([$company->legal_name, $company->address, $company->phone, $company->email]) as $i => $line) {
            $companyHtml .= '<div'.($i === 0 ? ' style="font-weight:700;"' : '').'>'.$e($line).'</div>';
        }

        $taxRows = '';
        foreach ([['VAT', (int) $invoice->vat_minor], ['NHIL', (int) $invoice->nhil_minor], ['GETFund', (int) $invoice->getfund_minor]] as [$label, $minor]) {
            if ($minor > 0) {
                $taxRows .= '<tr><td>'.$label.'</td><td style="text-align:right;">'.$currency.' '.$money($minor).'</td></tr>';
            }
        }

        $logo = rtrim((string) config('app.url'), '/').'/logo.png';
        $html = '<!DOCTYPE html><html><body style="margin:0;background:#f3f4f6;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">'
            .'<div style="max-width:850px;margin:24px auto;background:#fff;padding:32px 40px;">'
            .'<div style="text-align:center;color:#1c8ba6;font-size:32px;font-weight:300;margin-bottom:16px;">Invoice</div>'
            .'<table style="width:100%;font-size:12px;"><tr>'
            .'<td style="width:33%;vertical-align:top;line-height:1.5;">'.$companyHtml.'<div style="height:12px;"></div>'
            .'<div style="font-weight:700;">'.$e($customer->name ?? '').'</div><div>'.$e($customer->delivery_address ?: $customer->address ?? '').'</div></td>'
            .'<td style="width:34%;text-align:center;vertical-align:top;">'
            .'<img src="'.$e($logo).'" alt="'.$e($company->legal_name).'" width="160" style="display:block;margin:0 auto;width:160px;height:auto;background:#000;">'
            .'</td>'
            .'<td style="width:33%;text-align:right;vertical-align:top;font-weight:600;line-height:1.6;">'
            .'Invoice No: '.$e($invoice->invoice_no).'<br>Date: '.$e((string) $invoice->invoice_date?->format('d/m/Y')).'<br>'
            .'Due: '.$e((string) ($invoice->due_date?->format('d/m/Y') ?? '—')).'<br>Customer: '.$e($customer->code ?? '').'</td>'
            .'</tr></table>'
            .'<table style="width:100%;margin-top:18px;border-collapse:collapse;font-size:12px;">'
            .'<tr style="background:#016a8b;color:#fff;"><td style="padding:6px 10px;">Job</td><td style="padding:6px 10px;text-align:center;">Payment Terms</td><td style="padding:6px 10px;text-align:center;">Due Date</td></tr>'
            .'<tr><td style="padding:8px 10px;">'.$e($invoice->memo ?: 'Invoice '.$invoice->invoice_no).'</td><td style="padding:8px 10px;text-align:center;">Cash/Bank Transfer</td><td style="padding:8px 10px;text-align:center;">'.$e((string) ($invoice->due_date?->format('d/m/Y') ?? '—')).'</td></tr></table>'
            .'<table style="width:100%;border-collapse:collapse;margin-top:14px;font-size:11px;">'
            .'<tr style="background:#016a8b;color:#fff;"><th style="padding:6px;">#</th><th style="padding:6px;">Part No.</th><th style="padding:6px;text-align:left;">Description</th><th style="padding:6px;">Qty</th><th style="padding:6px;text-align:right;">Unit Price</th><th style="padding:6px;text-align:right;">Line Total</th></tr>'
            .$rows.'</table><div style="height:12px;background:#96e3f8;margin-top:6px;"></div>'
            .'<table style="width:280px;margin-left:auto;margin-top:16px;font-size:12px;">'
            .'<tr><td style="font-weight:700;">SUB-TOTAL</td><td style="text-align:right;font-weight:700;">'.$currency.' '.$money((int) $invoice->net_minor).'</td></tr>'
            .$taxRows
            .'<tr><td style="font-weight:700;padding-top:8px;">AMOUNT DUE</td><td style="text-align:right;padding-top:8px;"><span style="background:#36b6e4;color:#fff;font-weight:700;padding:4px 10px;">'.$currency.' '.$money((int) $invoice->gross_minor).'</span></td></tr>'
            .'</table>'
            .'<div style="margin-top:28px;border-top:1px solid #96e3f8;padding-top:12px;font-size:11px;">'
            .'<div style="font-weight:700;text-decoration:underline;color:#025b7a;">NOTES:</div>'
            .'<div style="font-weight:600;margin-top:4px;">Payment due by '.$e((string) ($invoice->due_date?->format('d/m/Y') ?? 'receipt')).'</div>'
            .'<div style="margin-top:4px;">'.$bankLine.'</div>'
            .'<div style="text-align:center;margin-top:18px;font-weight:600;">This invoice is subject to '.$e($company->legal_name).'\'s General Terms and Conditions</div>'
            .'</div></div></body></html>';

        $totals = [['label' => 'SUB-TOTAL', 'value' => $currency.' '.$money((int) $invoice->net_minor)]];
        if ((int) $invoice->vat_minor > 0) {
            $totals[] = ['label' => 'VAT', 'value' => $currency.' '.$money((int) $invoice->vat_minor)];
        }
        if ((int) $invoice->nhil_minor > 0) {
            $totals[] = ['label' => 'NHIL', 'value' => $currency.' '.$money((int) $invoice->nhil_minor)];
        }
        if ((int) $invoice->getfund_minor > 0) {
            $totals[] = ['label' => 'GETFund', 'value' => $currency.' '.$money((int) $invoice->getfund_minor)];
        }
        $totals[] = ['label' => 'AMOUNT DUE', 'value' => $currency.' '.$money((int) $invoice->gross_minor), 'emphasis' => true];

        $pdf = SheetPdf::render([
            'title' => 'Invoice',
            'company' => array_values(array_filter([(string) $company->legal_name, (string) ($company->address ?? ''), (string) ($company->phone ?? '')])),
            'customer' => array_values(array_filter([(string) ($customer->name ?? ''), (string) ($customer->delivery_address ?: $customer->address ?? '')])),
            'meta' => [
                'Invoice No: '.$invoice->invoice_no,
                'Date: '.$invoice->invoice_date?->format('d/m/Y'),
                'Due: '.($invoice->due_date?->format('d/m/Y') ?? '—'),
                'Customer: '.($customer->code ?? ''),
            ],
            'job' => (string) ($invoice->memo ?: 'Invoice '.$invoice->invoice_no),
            'terms' => 'Cash/Bank Transfer',
            'expiry' => (string) ($invoice->due_date?->format('d/m/Y') ?? '—'),
            'rows' => $pdfRows,
            'totals' => $totals,
            'notes' => [
                'Payment due by '.($invoice->due_date?->format('d/m/Y') ?? 'receipt'),
                $bank ? sprintf('%s — %s, %s', $bank->bank_name, $bank->account_name, $bank->account_number) : 'Bank details on request.',
            ],
            'footer' => 'This invoice is subject to '.$company->legal_name.' terms and conditions.',
        ]);

        return ['html' => $html, 'pdf' => $pdf];
    }
}
