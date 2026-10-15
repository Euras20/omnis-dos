<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\BankStatementLine;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/bank/imports — import a bank statement CSV using a column mapping (template).
 *
 * All-or-nothing: every data row is validated before any write. If any row fails
 * validation the whole import is rejected and nothing is committed. Exact-duplicate
 * statement lines (same line_hash) are skipped and counted; they do not fail the import.
 */
final class ImportBankStatement
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.match');

        $companyId = $this->companyContext->companyId();
        $bankAccountId = (string) ($data['bankAccountId'] ?? '');
        $fileName = isset($data['fileName']) ? trim((string) $data['fileName']) : null;
        $csv = (string) ($data['csv'] ?? '');
        $mapping = is_array($data['mapping'] ?? null) ? $data['mapping'] : [];
        $requestId = $this->companyContext->requestId();

        if ($bankAccountId === '') {
            throw new ValidationException('bankAccountId is required.', ['bankAccountId' => ['Required']]);
        }
        if (trim($csv) === '') {
            throw new ValidationException('csv content is required.', ['csv' => ['Required']]);
        }

        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('id', $bankAccountId)
            ->first();
        if (! $account) {
            throw new NotFoundException('Bank account not found.');
        }

        $hasHeader = (bool) ($mapping['hasHeader'] ?? true);
        $dateFormat = (string) ($mapping['dateFormat'] ?? 'Y-m-d');
        $amountMode = (string) ($mapping['amountMode'] ?? 'signed');
        $decimalSeparator = (string) ($mapping['decimalSeparator'] ?? '.');

        if (! in_array($amountMode, ['signed', 'separate'], true)) {
            throw new ValidationException('amountMode must be signed or separate.', ['mapping.amountMode' => ['Invalid']]);
        }

        $rows = $this->parseCsv($csv);
        if ($rows === []) {
            throw new ValidationException('CSV contains no rows.', ['csv' => ['Empty']]);
        }

        $header = $hasHeader ? array_shift($rows) : null;
        if ($rows === []) {
            throw new ValidationException('CSV contains no data rows.', ['csv' => ['No data rows']]);
        }

        $col = function (string $key) use ($mapping, $header): ?int {
            $v = $mapping[$key] ?? null;
            if ($v === null || $v === '') {
                return null;
            }
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                return (int) $v;
            }
            if (is_string($v) && $header !== null) {
                $idx = array_search($v, $header, true);
                return $idx === false ? null : (int) $idx;
            }
            return null;
        };

        $dateCol = $col('dateColumn');
        $refCol = $col('referenceColumn');
        $descCol = $col('descriptionColumn');
        $amountCol = $col('amountColumn');
        $debitCol = $col('debitColumn');
        $creditCol = $col('creditColumn');

        if ($dateCol === null) {
            throw new ValidationException('mapping.dateColumn is required.', ['mapping.dateColumn' => ['Required']]);
        }
        if ($amountMode === 'signed' && $amountCol === null) {
            throw new ValidationException('mapping.amountColumn is required for signed mode.', ['mapping.amountColumn' => ['Required']]);
        }
        if ($amountMode === 'separate' && ($debitCol === null || $creditCol === null)) {
            throw new ValidationException('mapping.debitColumn and creditColumn are required for separate mode.', [
                'mapping.debitColumn' => ['Required'],
                'mapping.creditColumn' => ['Required'],
            ]);
        }

        $errors = [];
        $parsed = [];

        foreach ($rows as $i => $row) {
            $lineNo = $i + 1 + ($hasHeader ? 1 : 0);
            $prefix = 'rows.'.$lineNo;

            $rawDate = $row[$dateCol] ?? '';
            $txnDate = $this->parseDate(trim((string) $rawDate), $dateFormat);
            if ($txnDate === null) {
                $errors[$prefix.'.date'][] = 'Unrecognised date (expected '.$dateFormat.').';
            }

            $reference = $refCol !== null ? trim((string) ($row[$refCol] ?? '')) : null;
            $description = $descCol !== null ? trim((string) ($row[$descCol] ?? '')) : null;

            $amountMinor = null;
            $direction = null;

            if ($amountMode === 'signed') {
                $raw = trim((string) ($row[$amountCol] ?? ''));
                $parsedAmt = $this->parseAmount($raw, $decimalSeparator);
                if ($parsedAmt === null) {
                    $errors[$prefix.'.amount'][] = 'Invalid amount.';
                } else {
                    $amountMinor = abs($parsedAmt);
                    $direction = $parsedAmt >= 0 ? 'credit' : 'debit';
                }
            } else {
                $rawDr = trim((string) ($row[$debitCol] ?? ''));
                $rawCr = trim((string) ($row[$creditCol] ?? ''));
                $dr = $rawDr === '' ? 0 : $this->parseAmount($rawDr, $decimalSeparator);
                $cr = $rawCr === '' ? 0 : $this->parseAmount($rawCr, $decimalSeparator);
                if ($dr === null || $cr === null) {
                    $errors[$prefix.'.amount'][] = 'Invalid debit/credit amount.';
                } elseif ($dr > 0 && $cr > 0) {
                    $errors[$prefix.'.amount'][] = 'Row has both debit and credit.';
                } elseif ($dr === 0 && $cr === 0) {
                    $errors[$prefix.'.amount'][] = 'Amount is zero.';
                } else {
                    $amountMinor = $dr > 0 ? $dr : $cr;
                    $direction = $dr > 0 ? 'debit' : 'credit';
                }
            }

            if ($txnDate !== null && $amountMinor !== null && $direction !== null) {
                $parsed[] = [
                    'lineNo' => $lineNo,
                    'txnDate' => $txnDate,
                    'reference' => $reference !== '' ? $reference : null,
                    'description' => $description !== '' ? $description : null,
                    'amountMinor' => $amountMinor,
                    'direction' => $direction,
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::fromFields($errors, 'One or more rows failed validation. Nothing was imported.');
        }

        $fileHash = hash('sha256', $csv);
        $existing = BankImport::query()
            ->where('company_id', $companyId)
            ->where('file_hash', $fileHash)
            ->first();
        if ($existing) {
            throw new BusinessRuleException('This file has already been imported.', 'duplicate_file');
        }

        $existingHashes = BankStatementLine::query()
            ->where('company_id', $companyId)
            ->where('bank_account_id', $bankAccountId)
            ->pluck('line_hash')
            ->flip()
            ->all();

        $toInsert = [];
        $skipped = 0;

        foreach ($parsed as $p) {
            $lineHash = hash('sha256', implode('|', [
                $bankAccountId,
                $p['txnDate'],
                (string) $p['reference'],
                (string) $p['description'],
                (string) $p['amountMinor'],
                $p['direction'],
            ]));
            if (isset($existingHashes[$lineHash])) {
                $skipped++;
                continue;
            }
            $toInsert[] = $p + ['lineHash' => $lineHash];
        }

        return DB::transaction(function () use (
            $companyId,
            $bankAccountId,
            $fileName,
            $fileHash,
            $mapping,
            $toInsert,
            $skipped,
            $actorId,
            $requestId,
        ) {
            $importId = Uuid::v7();
            $import = BankImport::query()->create([
                'id' => $importId,
                'company_id' => $companyId,
                'bank_account_id' => $bankAccountId,
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'column_mapping' => $mapping,
                'line_count' => count($toInsert),
                'skipped_count' => $skipped,
                'imported_by' => $actorId,
                'imported_at' => now(),
                'request_id' => $requestId,
            ]);

            foreach ($toInsert as $line) {
                BankStatementLine::query()->create([
                    'company_id' => $companyId,
                    'bank_import_id' => $importId,
                    'bank_account_id' => $bankAccountId,
                    'txn_date' => $line['txnDate'],
                    'reference' => $line['reference'],
                    'description' => $line['description'],
                    'amount_minor' => $line['amountMinor'],
                    'direction' => $line['direction'],
                    'currency' => 'GHS',
                    'status' => 'unmatched',
                    'line_hash' => $line['lineHash'],
                ]);
            }

            $this->auditWriter->write(
                companyId: $companyId,
                actorId: $actorId,
                action: 'bank_statement.imported',
                subjectType: 'bank_import',
                subjectId: $importId,
                payload: [
                    'fileName' => $fileName,
                    'lineCount' => count($toInsert),
                    'skippedCount' => $skipped,
                    'bankAccountId' => $bankAccountId,
                ],
                requestId: $requestId,
            );

            $this->outboxWriter->write(
                companyId: $companyId,
                type: 'bank_statement.imported',
                payload: [
                    'importId' => $importId,
                    'bankAccountId' => $bankAccountId,
                    'lineCount' => count($toInsert),
                ],
                requestId: $requestId,
            );

            return [
                'id' => $import->id,
                'fileName' => $import->file_name,
                'lineCount' => (int) $import->line_count,
                'skippedCount' => (int) $import->skipped_count,
                'importedAt' => $import->imported_at?->toIso8601String(),
                'bankAccountId' => $import->bank_account_id,
            ];
        });
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsv(string $csv): array
    {
        $csv = str_replace("\r\n", "\n", $csv);
        $csv = str_replace("\r", "\n", $csv);
        $lines = array_filter(explode("\n", $csv), fn ($l) => trim($l) !== '');
        $out = [];
        foreach ($lines as $line) {
            $out[] = str_getcsv($line);
        }
        return $out;
    }

    private function parseDate(string $raw, string $format): ?string
    {
        if ($raw === '') {
            return null;
        }
        $dt = \DateTime::createFromFormat($format, $raw);
        if ($dt === false) {
            return null;
        }
        return $dt->format('Y-m-d');
    }

    private function parseAmount(string $raw, string $decimalSeparator): ?int
    {
        if ($raw === '') {
            return null;
        }
        $clean = str_replace([' ', "\u{00A0}"], '', $raw);
        if ($decimalSeparator === ',') {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }
        if (! is_numeric($clean)) {
            return null;
        }
        return (int) round((float) $clean * 100);
    }
}
