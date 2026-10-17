<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Http\Controllers\Controller;
use App\Modules\Treasury\Actions\ExcludeBankLine;
use App\Modules\Treasury\Actions\MatchBankLine;
use App\Modules\Treasury\Actions\UnmatchBankLine;
use App\Modules\Treasury\Queries\GetBankLineCandidatesQuery;
use App\Modules\Treasury\Queries\ListBankStatementLinesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BankLineController extends Controller
{
    public function index(Request $request, ListBankStatementLinesQuery $query): JsonResponse
    {
        return response()->json($query->execute($request->query()));
    }

    public function candidates(int|string $id, GetBankLineCandidatesQuery $query): JsonResponse
    {
        return response()->json($query->execute($id));
    }

    public function match(int|string $id, Request $request, MatchBankLine $action): JsonResponse
    {
        return response()->json($action->execute($id, $request->all()));
    }

    public function exclude(int|string $id, Request $request, ExcludeBankLine $action): JsonResponse
    {
        return response()->json($action->execute($id, $request->all()));
    }

    public function unmatch(int|string $id, UnmatchBankLine $action): JsonResponse
    {
        return response()->json($action->execute($id));
    }
}
