<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the published DOS firm document layouts for quotation, purchase order
 * and invoice. These match the client sample PDFs so print / PDF uses them
 * by default.
 *
 * Idempotent: skips a type if a published row already exists for the company.
 */
final class DocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = DemoSeedSeeder::COMPANY_ID;
        $now = now();

        foreach ($this->templates() as $type => $settings) {
            $exists = DB::table('document_template')
                ->where('company_id', $companyId)
                ->where('doc_type', $type)
                ->where('status', 'published')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('document_template')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'doc_type' => $type,
                'status' => 'published',
                'version' => 1,
                'settings' => json_encode($settings),
                'published_by' => null,
                'published_at' => $now,
                'row_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            'quotation' => $this->quotation(),
            'purchase_order' => $this->purchaseOrder(),
            'invoice' => $this->invoice(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quotation(): array
    {
        return [
            'title' => 'Quotation',
            'color' => '#0E86A8',
            'cur' => 'GHC',
            'titleAlign' => 'right',
            'metaStyle' => 'list',
            'detailsStyle' => 'band',
            'partyStyle' => 'plain',
            'header' => 'solid',
            'zebra' => '#D9F1F8',
            'grid' => false,
            'minRows' => 2,
            'curInLines' => true,
            'totalsStyle' => 'boxed',
            'closing' => "{{tax_note}}\nINCOTERMS: {{incoterm}}",
            'terms' => '',
            'fineprint' => false,
            'signature' => '',
            'cols' => [
                ['k' => 'line_no', 'l' => 'Item No.', 'w' => 6, 'show' => true],
                ['k' => 'sku', 'l' => 'Part No.', 'w' => 16, 'show' => true],
                ['k' => 'description', 'l' => 'Description', 'w' => 31, 'show' => true],
                ['k' => 'qty', 'l' => 'Quantity', 'w' => 8, 'show' => true],
                ['k' => 'lead_time', 'l' => 'Lead Time', 'w' => 8, 'show' => true],
                ['k' => 'unit_price', 'l' => 'Unit Price', 'w' => 15, 'show' => true],
                ['k' => 'amount', 'l' => 'Line Total', 'w' => 16, 'show' => true],
            ],
            'tots' => [
                ['k' => 'subtotal', 'l' => 'SUB-TOTAL', 'show' => true],
                ['k' => 'discount', 'l' => 'Discount ({{discount_pct}}%)', 'show' => true],
                ['k' => 'total_after', 'l' => 'TOTAL', 'show' => true],
                ['k' => 'taxes', 'l' => 'TAXES & LEVIES (NHIL, GETFund, VAT)', 'show' => true],
                ['k' => 'grand', 'l' => 'GRAND-TOTAL', 'show' => true],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseOrder(): array
    {
        return [
            'title' => 'Purchase order',
            'color' => '#1F2937',
            'cur' => 'GHS',
            'titleAlign' => 'center',
            'metaStyle' => 'strip',
            'detailsStyle' => 'list',
            'partyStyle' => 'boxed',
            'header' => 'tint',
            'zebra' => '',
            'grid' => true,
            'minRows' => 10,
            'curInLines' => false,
            'totalsStyle' => 'plain',
            'closing' => 'The acceptance of our purchase order implies full agreement with our general purchase conditions (copy enclosed), and specific conditions as indicated below.',
            'terms' => 'The BUYER and the SELLER agree that the Purchase Order is dematerialized and will be sent in an electronic format, which does not exclude the BUYER from using any other means of materialized transactions. The BUYER and the SELLER agree that the dematerialized Purchase Order and the dematerialized notification of acknowledgement of receipt of this Purchase Order consist of an electronic signature which has, between the BUYER and the SELLER, the same value than a handwritten signature which constitutes proof of the acceptance of a Purchase Order by the SELLER. Thus, the BUYER and the SELLER agree that computerized registries, kept in computer systems in reasonable security conditions, will be considered as proof of all electronic messages between the BUYER and the SELLER and, when necessary, payments made. In particular, if a date or time limit is established, only the BUYER\'S computerized time stamping systems will be accepted as proof. The BUYER and the SELLER therefore expressly waive the right to question the opportunity of choices or to contest on this basis a contractual obligation following an exchange of electronic messages, kept in the conditions defined above.',
            'fineprint' => true,
            'signature' => 'SIGNED BY: {{approver}}',
            'cols' => [
                ['k' => 'line_no', 'l' => 'REF.', 'w' => 5, 'show' => true],
                ['k' => 'description', 'l' => 'DESCRIPTION', 'w' => 22, 'show' => true],
                ['k' => 'sku', 'l' => 'PART NO.', 'w' => 14, 'show' => true],
                ['k' => 'uom', 'l' => 'UOM', 'w' => 6, 'show' => true],
                ['k' => 'qty', 'l' => 'QUANTITY', 'w' => 10, 'show' => true],
                ['k' => 'unit_price', 'l' => 'UNIT PRICE (EXCL. TAX)', 'w' => 12, 'show' => true],
                ['k' => 'discount', 'l' => 'DISCOUNT', 'w' => 10, 'show' => true],
                ['k' => 'amount', 'l' => 'TOTAL AMOUNT (EXCL. TAX)', 'w' => 13, 'show' => true],
                ['k' => 'delivery_date', 'l' => 'DELIVERY DATE', 'w' => 11, 'show' => true],
            ],
            'tots' => [
                ['k' => 'total_excl', 'l' => 'TOTAL AMOUNT (EXCL. TAX)', 'show' => true],
                ['k' => 'tax', 'l' => 'TAX AMOUNT', 'show' => true],
                ['k' => 'total_incl', 'l' => 'TOTAL AMOUNT (INCL TAX)', 'show' => true],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(): array
    {
        return [
            'title' => 'Tax invoice',
            'color' => '#0077A8',
            'cur' => 'GHS',
            'titleAlign' => 'right',
            'metaStyle' => 'list',
            'detailsStyle' => 'list',
            'partyStyle' => 'plain',
            'header' => 'tint',
            'zebra' => '',
            'grid' => false,
            'minRows' => 0,
            'curInLines' => false,
            'totalsStyle' => 'plain',
            'closing' => '',
            'terms' => '',
            'fineprint' => false,
            'signature' => '',
            'cols' => [
                ['k' => 'line_no', 'l' => '#', 'w' => 5, 'show' => true],
                ['k' => 'sku', 'l' => 'Part no.', 'w' => 16, 'show' => true],
                ['k' => 'description', 'l' => 'Description', 'w' => 46, 'show' => true],
                ['k' => 'qty', 'l' => 'Qty', 'w' => 9, 'show' => true],
                ['k' => 'unit_price', 'l' => 'Unit price', 'w' => 16, 'show' => true],
                ['k' => 'amount', 'l' => 'Amount', 'w' => 18, 'show' => true],
            ],
            'tots' => [
                ['k' => 'subtotal', 'l' => 'Subtotal', 'show' => true],
                ['k' => 'vat', 'l' => 'VAT', 'show' => true],
                ['k' => 'nhil', 'l' => 'NHIL', 'show' => true],
                ['k' => 'getfund', 'l' => 'GETFund levy', 'show' => true],
                ['k' => 'grand', 'l' => 'Total', 'show' => true],
                ['k' => 'outstanding', 'l' => 'Amount outstanding', 'show' => true],
            ],
        ];
    }
}
