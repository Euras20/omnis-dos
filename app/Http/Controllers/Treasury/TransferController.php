<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Http\Controllers\Controller;
use App\Modules\Treasury\Actions\PostCashTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TransferController extends Controller
{
    public function store(Request $request, PostCashTransfer $action): JsonResponse
    {
        return response()->json($action->execute($request->all()), 201);
    }
}
