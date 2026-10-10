<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Requests\Inventory\StoreDeliveryRequest;
use App\Modules\Inventory\Actions\PostDelivery;
use Illuminate\Http\JsonResponse;

final class DeliveryController
{
    public function __construct(
        private readonly PostDelivery $postDelivery,
    ) {
    }

    public function store(StoreDeliveryRequest $request): JsonResponse
    {
        $delivery = $this->postDelivery->execute($request->validated());

        return response()->json($delivery, 201);
    }
}
