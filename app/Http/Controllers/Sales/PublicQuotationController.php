<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Party;
use App\Models\Quotation;
use App\Modules\Sales\Support\QuotationDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public quotation PDF. Token is an HMAC of the id, so the customer can download
 * without a login. No other data is exposed.
 */
final class PublicQuotationController extends Controller
{
    public function pdf(string $id, Request $request): Response
    {
        $token = (string) $request->query('token', '');
        if ($token === '' || ! QuotationDocument::tokenValid($id, $token)) {
            abort(403, 'Invalid download link.');
        }

        $quotation = Quotation::query()->withoutGlobalScopes()->with('lines')->where('id', $id)->first();
        if ($quotation === null) {
            abort(404);
        }

        $company = Company::query()->where('id', $quotation->company_id)->firstOrFail();
        $customer = Party::query()->withoutGlobalScopes()->where('id', $quotation->customer_id)->first();
        $banks = CompanyBankAccount::query()
            ->withoutGlobalScopes()
            ->where('company_id', $quotation->company_id)
            ->where('is_active', true)
            ->orderBy('bank_name')
            ->get();

        $doc = QuotationDocument::build($quotation, $customer, $company, $banks, $request->fullUrl());

        return response($doc['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$quotation->quotation_no.'.pdf"',
        ]);
    }
}
