<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Models\AppUser;
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
        /** @var AppUser $principal */
        $principal = $request->user();

        // Reload so columns the auth principal omits (signature_path) are present.
        $user = AppUser::query()->find($principal->id) ?? $principal;

        return response()->json($this->getMeQuery->execute($user));
    }
}
