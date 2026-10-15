<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Http\Controllers\Controller;
use App\Models\BankImport;
use App\Modules\Treasury\Actions\ImportBankStatement;
use App\Shared\Company\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BankImportController extends Controller
{
    public function index(CompanyContext $companyContext): JsonResponse
    {
        $rows = BankImport::query()
            ->where('company_id', $companyContext->companyId())
            ->orderByDesc('imported_at')
            ->limit(100)
            ->get()
            ->map(fn (BankImport $i) => [
                'id' => $i->id,
                'fileName' => $i->file_name,
                'lineCount' => (int) $i->line_count,
                'skippedCount' => (int) $i->skipped_count,
                'importedAt' => $i->imported_at?->toIso8601String(),
                'bankAccountId' => $i->bank_account_id,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, ImportBankStatement $action): JsonResponse
    {
        return response()->json($action->execute($request->all()), 201);
    }
}
