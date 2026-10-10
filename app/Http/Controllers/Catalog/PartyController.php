<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Requests\Catalog\StorePartyRequest;
use App\Http\Requests\Catalog\UpdatePartyRequest;
use App\Modules\Catalog\Actions\CreateParty;
use App\Modules\Catalog\Actions\UpdateParty;
use App\Modules\Catalog\Queries\GetPartyQuery;
use App\Modules\Catalog\Queries\ListPartiesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PartyController
{
    public function __construct(
        private readonly ListPartiesQuery $listPartiesQuery,
        private readonly GetPartyQuery $getPartyQuery,
        private readonly CreateParty $createParty,
        private readonly UpdateParty $updateParty,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $result = $this->listPartiesQuery->execute([
            'q' => $request->query('q'),
            'type' => $request->query('type'),
            'status' => $request->query('status'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]);

        return response()->json($result);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->getPartyQuery->execute($id));
    }

    public function store(StorePartyRequest $request): JsonResponse
    {
        $party = $this->createParty->execute($request->validated());

        return response()->json($party, 201);
    }

    public function update(string $id, UpdatePartyRequest $request): JsonResponse
    {
        $party = $this->updateParty->execute($id, $request->validated());

        return response()->json($party);
    }
}
