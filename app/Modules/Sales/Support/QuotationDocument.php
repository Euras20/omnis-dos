<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Party;
use App\Models\Quotation;

/**
 * Quotation letterhead used for the customer email and the downloadable PDF.
 */
final class QuotationDocument
{
    /**
     * @param  \Illuminate\Support\Collection<int, CompanyBankAccount>  $banks
     * @return array{html: string, pdf: string, lines: list<string>}
     */
    public static function build(Quotation $quotation, ?Party $customer, Company $company, $banks, string $downloadUrl): array
    {
        $money = static fn (int $minor): string => number_format($minor / 100, 2);
        $currency = (string) $quotation->currency;
        $rows = '';
        $pdfLines = [
            $company->legal_name,
            (string) ($company->address ?? ''),
            'Quote '.$quotation->quotation_no.'   Date '.$quotation->quotation_date?->format('d/m/Y'),
            'Customer: '.($customer->name ?? ''),
            'Delivery: '.trim(($quotation->delivery_type ?? '').' '.($quotation->delivery_location ?? '')),
            '',
        ];

        foreach ($quotation->lines as $line) {
            $rows .= '<tr>'
                .'<td style="padding:8px;border-bottom:1px solid #e5e7eb;">'.(int) $line->line_no.'</td>'
                .'<td style="padding:8px;border-bottom:1px solid #e5e7eb;">'.e($line->description).'</td>'
                .'<td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;">'.e((string) $line->qty).'</td>'
                .'<td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;">'.$currency.' '.$money((int) $line->unit_price_minor).'</td>'
                .'<td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;">'.$currency.' '.$money((int) $line->net_minor).'</td>'
                .'</tr>';
            $pdfLines[] = sprintf('%s  x%s  %s %s', $line->description, $line->qty, $currency, $money((int) $line->net_minor));
        }

        $discount = (float) $quotation->discount_percent;
        $bankBlock = '';
        $pdfLines[] = '';
        $pdfLines[] = 'Sub-total '.$currency.' '.$money((int) $quotation->net_minor);
        if ($discount > 0) {
            $pdfLines[] = 'Discount '.$discount.'%';
        }
        $pdfLines[] = 'Tax '.$currency.' '.$money((int) $quotation->tax_total_minor);
        $pdfLines[] = 'Grand total '.$currency.' '.$money((int) $quotation->gross_minor);
        $pdfLines[] = '';
        $pdfLines[] = 'Pay to:';
        foreach ($banks as $bank) {
            $bankBlock .= '<div style="margin-top:4px;">'.e($bank->bank_name).' — '.e($bank->account_name)
                .', '.e($bank->account_number)
                .($bank->branch ? ' ('.e($bank->branch).')' : '')
                .($bank->swift ? ' · SWIFT '.e($bank->swift) : '')
                .'</div>';
            $pdfLines[] = sprintf('%s — %s, %s', $bank->bank_name, $bank->account_name, $bank->account_number);
        }
        if ($bankBlock === '') {
            $bankBlock = '<div>Bank details on request.</div>';
        }

        $html = '<!DOCTYPE html><html><body style="margin:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
            .'<div style="max-width:720px;margin:24px auto;background:#fff;border:1px solid #e5e7eb;">'
            .'<div style="padding:28px 32px 12px;border-bottom:4px solid #1e4b8f;">'
            .'<div style="font-size:13px;color:#6b7280;">'.e($company->legal_name).'</div>'
            .'<div style="font-size:12px;color:#6b7280;white-space:pre-line;">'.e((string) ($company->address ?? '')).'</div>'
            .'<div style="font-size:28px;color:#1e4b8f;font-weight:700;margin-top:16px;">Quotation</div>'
            .'</div>'
            .'<div style="padding:20px 32px;">'
            .'<table style="width:100%;font-size:13px;"><tr>'
            .'<td style="vertical-align:top;"><strong>Bill to</strong><br>'.e($customer->name ?? '').'<br>'.e((string) ($customer->delivery_address ?: $customer->address ?? '')).'</td>'
            .'<td style="vertical-align:top;text-align:right;">'
            .'Quote No: <strong>'.e($quotation->quotation_no).'</strong><br>'
            .'Date: '.e((string) $quotation->quotation_date?->format('d/m/Y')).'<br>'
            .'Valid until: '.e((string) ($quotation->valid_until?->format('d/m/Y') ?? '—')).'<br>'
            .'Delivery: '.e(trim(($quotation->delivery_type ?? '').' '.($quotation->delivery_location ?? ''))).'</td>'
            .'</tr></table>'
            .'<table style="width:100%;border-collapse:collapse;margin-top:20px;font-size:13px;">'
            .'<thead><tr style="background:#1e4b8f;color:#fff;">'
            .'<th style="padding:8px;text-align:left;">#</th><th style="padding:8px;text-align:left;">Description</th>'
            .'<th style="padding:8px;text-align:right;">Qty</th><th style="padding:8px;text-align:right;">Unit price</th>'
            .'<th style="padding:8px;text-align:right;">Line total</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<table style="width:280px;margin-left:auto;margin-top:16px;font-size:13px;">'
            .'<tr><td>Sub-total</td><td style="text-align:right;">'.$currency.' '.$money((int) $quotation->net_minor).'</td></tr>'
            .($discount > 0 ? '<tr><td>Discount ('.$discount.'%)</td><td style="text-align:right;">applied</td></tr>' : '')
            .'<tr><td>Tax</td><td style="text-align:right;">'.$currency.' '.$money((int) $quotation->tax_total_minor).'</td></tr>'
            .'<tr style="font-weight:700;font-size:15px;"><td style="padding-top:8px;">Grand total</td><td style="text-align:right;padding-top:8px;">'.$currency.' '.$money((int) $quotation->gross_minor).'</td></tr>'
            .'</table>'
            .'<div style="margin-top:24px;padding:14px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;">'
            .'<strong>Pay to</strong>'.$bankBlock.'</div>'
            .'<div style="text-align:center;margin:28px 0;">'
            .'<a href="'.e($downloadUrl).'" style="background:#1e4b8f;color:#fff;text-decoration:none;padding:12px 22px;border-radius:4px;font-weight:700;">Download PDF</a>'
            .'</div>'
            .'<div style="font-size:11px;color:#6b7280;">This offer is subject to '.e($company->legal_name).' terms and conditions.</div>'
            .'</div></div></body></html>';

        return [
            'html' => $html,
            'pdf' => SimplePdf::fromLines('Quotation '.$quotation->quotation_no, $pdfLines),
            'lines' => $pdfLines,
        ];
    }

    public static function token(string $quotationId): string
    {
        return hash_hmac('sha256', $quotationId, (string) config('app.key'));
    }

    public static function tokenValid(string $quotationId, string $token): bool
    {
        return hash_equals(self::token($quotationId), $token);
    }
}
