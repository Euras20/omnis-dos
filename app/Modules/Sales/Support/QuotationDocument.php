<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Party;
use App\Models\Quotation;

/**
 * Quotation letterhead matching the Dos Santos quote sheet.
 * Inline styles only — email clients do not run Tailwind.
 */
final class QuotationDocument
{
    /**
     * @param  \Illuminate\Support\Collection<int, CompanyBankAccount>  $banks
     * @return array{html: string, pdf: string}
     */
    public static function build(Quotation $quotation, ?Party $customer, Company $company, $banks, string $downloadUrl): array
    {
        $currency = (string) ($quotation->currency ?: 'GHS');
        $money = static fn (int $minor): string => number_format($minor / 100, 2);
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $lineSum = 0;
        $rows = '';

        foreach ($quotation->lines as $i => $line) {
            $lineSum += (int) $line->net_minor;
            $part = $line->item?->sku ?: '—';
            $bg = $i % 2 === 1 ? 'background:#c7effc;' : '';
            $rows .= '<tr style="'.$bg.'vertical-align:top;">'
                .'<td style="padding:8px 4px;text-align:center;">'.(int) $line->line_no.'</td>'
                .'<td style="padding:8px 6px;font-weight:700;">'.$e($part).'</td>'
                .'<td style="padding:8px 6px;">'.$e($line->description).'</td>'
                .'<td style="padding:8px 4px;text-align:center;font-weight:600;">'.$e((string) $line->qty).'</td>'
                .'<td style="padding:8px 4px;text-align:center;">—</td>'
                .'<td style="padding:8px 6px;text-align:right;">'.$currency.' '.$money((int) $line->unit_price_minor).'</td>'
                .'<td style="padding:8px 6px;text-align:right;">'.$currency.' '.$money((int) $line->net_minor).'</td>'
                .'</tr>';
        }

        $discountPct = (float) $quotation->discount_percent;
        $discountMinor = $discountPct > 0 ? (int) round($lineSum * $discountPct / 100) : 0;
        $totalMinor = $lineSum - $discountMinor;
        $taxMinor = (int) $quotation->tax_total_minor;
        $grandMinor = (int) $quotation->gross_minor;

        $bank = $banks->first();
        $bankLine = $bank
            ? 'Payments via bank transfer to <strong>'.$e($bank->account_name).'</strong> Account No: <strong>'.$e($bank->account_number).'</strong>, '.$e($bank->bank_name).($bank->branch ? ', '.$e($bank->branch) : '').($bank->swift ? ', Swift: <strong>'.$e($bank->swift).'</strong>' : '')
            : 'Bank details on request.';

        $incoterms = trim(($quotation->delivery_type ?? '').' '.($quotation->delivery_location ?? ''));
        $job = $quotation->memo ?: 'Quotation '.$quotation->quotation_no;

        $companyLines = array_filter([
            $company->legal_name,
            $company->address,
            $company->phone,
            $company->email,
        ]);
        $companyHtml = '';
        foreach ($companyLines as $i => $line) {
            $companyHtml .= '<div'.($i === 0 ? ' style="font-weight:700;"' : '').'>'.$e($line).'</div>';
        }
        $customerHtml = '<div style="font-weight:700;">'.$e($customer->contact_name ?: $customer->name ?? '').'</div>'
            .'<div>'.$e($customer->name ?? '').'</div>'
            .'<div>'.$e($customer->delivery_address ?: $customer->address ?? '').'</div>';

        $logo = rtrim((string) config('app.url'), '/').'/logo.png';
        $html = '<!DOCTYPE html><html><body style="margin:0;background:#f3f4f6;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">'
            .'<div style="max-width:850px;margin:24px auto;background:#fff;padding:32px 40px;">'
            .'<div style="text-align:center;color:#1c8ba6;font-size:32px;font-weight:300;letter-spacing:1px;margin-bottom:16px;">Quotation</div>'
            .'<table style="width:100%;font-size:12px;"><tr>'
            .'<td style="width:33%;vertical-align:top;line-height:1.5;">'.$companyHtml.'<div style="height:12px;"></div>'.$customerHtml.'</td>'
            .'<td style="width:34%;text-align:center;vertical-align:top;">'
            .'<img src="'.$e($logo).'" alt="'.$e($company->legal_name).'" width="160" style="display:block;margin:0 auto;width:160px;height:auto;">'
            .'</td>'
            .'<td style="width:33%;text-align:right;vertical-align:top;font-weight:600;line-height:1.6;">'
            .'Quote No: '.$e($quotation->quotation_no).'<br>'
            .'Date: '.$e((string) $quotation->quotation_date?->format('d/m/Y')).'<br>'
            .'Customer ID: '.$e($customer->code ?? '').'<br>'
            .'Valid until: '.$e((string) ($quotation->valid_until?->format('d/m/Y') ?? '—')).'</td>'
            .'</tr></table>'
            .'<table style="width:100%;margin-top:18px;border-collapse:collapse;font-size:12px;">'
            .'<tr style="background:#016a8b;color:#fff;"><td style="padding:6px 10px;width:50%;">Job</td><td style="padding:6px 10px;text-align:center;width:25%;">Payment Terms</td><td style="padding:6px 10px;text-align:center;width:25%;">Quote Expiry Date</td></tr>'
            .'<tr><td style="padding:8px 10px;">'.$e($job).'</td><td style="padding:8px 10px;text-align:center;">Cash/Bank<br>Transfer</td><td style="padding:8px 10px;text-align:center;">'.$e((string) ($quotation->valid_until?->format('d/m/Y') ?? '—')).'</td></tr>'
            .'</table>'
            .'<table style="width:100%;border-collapse:collapse;margin-top:14px;font-size:11px;">'
            .'<tr style="background:#016a8b;color:#fff;text-align:center;">'
            .'<th style="padding:6px;">Item No.</th><th style="padding:6px;">Part No.</th><th style="padding:6px;text-align:left;">Description</th>'
            .'<th style="padding:6px;">Quantity</th><th style="padding:6px;">Lead Time</th><th style="padding:6px;text-align:right;">Unit Price</th><th style="padding:6px;text-align:right;">Line Total</th></tr>'
            .$rows
            .'</table>'
            .'<div style="height:12px;background:#96e3f8;margin-top:6px;"></div>'
            .'<table style="width:280px;margin-left:auto;margin-top:16px;font-size:12px;">'
            .'<tr><td style="font-weight:700;">SUB-TOTAL</td><td style="text-align:right;font-weight:700;">'.$currency.' '.$money($lineSum).'</td></tr>'
            .($discountMinor > 0 ? '<tr><td>Discount ('.$discountPct.'%)</td><td style="text-align:right;font-weight:700;">-'.$currency.' '.$money($discountMinor).'</td></tr>' : '')
            .'<tr><td style="font-weight:700;border-top:1px solid #cbd5e1;padding-top:4px;">TOTAL</td><td style="text-align:right;font-weight:700;border-top:1px solid #cbd5e1;padding-top:4px;">'.$currency.' '.$money($totalMinor).'</td></tr>'
            .'<tr><td style="font-weight:700;font-size:10px;">TAXES & LEVIES<br><span style="font-weight:400;color:#6b7280;">(NHIL, GETFund, VAT)</span></td><td style="text-align:right;font-weight:700;">'.($taxMinor > 0 ? $currency.' '.$money($taxMinor) : '(EXCLUDED)').'</td></tr>'
            .'<tr><td style="font-weight:700;padding-top:8px;">GRAND-TOTAL</td><td style="text-align:right;padding-top:8px;"><span style="background:#36b6e4;color:#fff;font-weight:700;padding:4px 10px;">'.$currency.' '.$money($grandMinor).'</span></td></tr>'
            .'</table>'
            .'<div style="text-align:center;margin-top:22px;color:#03344a;font-weight:700;font-size:11px;letter-spacing:0.5px;text-transform:uppercase;">'
            .'<div>'.($taxMinor > 0 ? 'Taxes are included' : 'Taxes are not included').'</div>'
            .($incoterms !== '' ? '<div>Incoterms: '.$e($incoterms).'</div>' : '')
            .'</div>'
            .'<div style="margin-top:28px;border-top:1px solid #96e3f8;padding-top:12px;font-size:11px;">'
            .'<div style="font-weight:700;text-decoration:underline;color:#025b7a;">NOTES:</div>'
            .'<div style="font-weight:600;margin-top:4px;">100% payment due upon order</div>'
            .'<div style="margin-top:4px;">'.$bankLine.'</div>'
            .'<div style="text-align:center;margin-top:18px;font-weight:600;">This Offer is subject to '.$e($company->legal_name).'\'s General Terms and Conditions</div>'
            .'</div></div></body></html>';

        return [
            'html' => $html,
            'pdf' => SheetPdf::render(self::sheet($quotation, $customer, $company, $banks, $lineSum, $discountPct, $discountMinor, $totalMinor, $taxMinor, $grandMinor, $currency, $money, $incoterms, $job)),
        ];
    }

    /**
     * @param  callable(int): string  $money
     * @param  \Illuminate\Support\Collection<int, CompanyBankAccount>  $banks
     * @return array<string, mixed>
     */
    private static function sheet(Quotation $quotation, ?Party $customer, Company $company, $banks, int $lineSum, float $discountPct, int $discountMinor, int $totalMinor, int $taxMinor, int $grandMinor, string $currency, callable $money, string $incoterms, string $job): array
    {
        $rows = [];
        foreach ($quotation->lines as $line) {
            $rows[] = [
                'no' => (string) $line->line_no,
                'part' => (string) ($line->item?->sku ?: '—'),
                'description' => (string) $line->description,
                'qty' => (string) $line->qty,
                'unit' => $currency.' '.$money((int) $line->unit_price_minor),
                'total' => $currency.' '.$money((int) $line->net_minor),
            ];
        }
        $bank = $banks->first();
        $notes = ['100% payment due upon order'];
        $notes[] = $bank
            ? sprintf('%s — %s, %s%s', $bank->bank_name, $bank->account_name, $bank->account_number, $bank->swift ? ' SWIFT '.$bank->swift : '')
            : 'Bank details on request.';
        if ($incoterms !== '') {
            $notes[] = 'Incoterms: '.$incoterms;
        }

        $totals = [['label' => 'SUB-TOTAL', 'value' => $currency.' '.$money($lineSum)]];
        if ($discountMinor > 0) {
            $totals[] = ['label' => 'Discount '.$discountPct.'%', 'value' => '-'.$currency.' '.$money($discountMinor)];
        }
        $totals[] = ['label' => 'TOTAL', 'value' => $currency.' '.$money($totalMinor)];
        $totals[] = ['label' => 'TAX', 'value' => $taxMinor > 0 ? $currency.' '.$money($taxMinor) : 'EXCLUDED'];
        $totals[] = ['label' => 'GRAND TOTAL', 'value' => $currency.' '.$money($grandMinor), 'emphasis' => true];

        return [
            'title' => 'Quotation',
            'company' => array_values(array_filter([(string) $company->legal_name, (string) ($company->address ?? ''), (string) ($company->phone ?? ''), (string) ($company->email ?? '')])),
            'customer' => array_values(array_filter([(string) ($customer->name ?? ''), (string) ($customer->delivery_address ?: $customer->address ?? '')])),
            'meta' => [
                'Quote No: '.$quotation->quotation_no,
                'Date: '.$quotation->quotation_date?->format('d/m/Y'),
                'Customer: '.($customer->code ?? ''),
                'Valid until: '.($quotation->valid_until?->format('d/m/Y') ?? '—'),
            ],
            'job' => $job,
            'terms' => 'Cash/Bank Transfer',
            'expiry' => (string) ($quotation->valid_until?->format('d/m/Y') ?? '—'),
            'rows' => $rows,
            'totals' => $totals,
            'notes' => $notes,
            'footer' => 'This offer is subject to '.$company->legal_name.' terms and conditions.',
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
