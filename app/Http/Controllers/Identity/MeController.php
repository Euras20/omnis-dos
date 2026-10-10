<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Modules\Identity\Queries\GetMeQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __construct(
        private readonly GetMeQuery $getMeQuery,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var \App\Models\AppUser $user */
        $user = $request->user();

        return response()->json($this->getMeQuery->execute($user));
    }
}
