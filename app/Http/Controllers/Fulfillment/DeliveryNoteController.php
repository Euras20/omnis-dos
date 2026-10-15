<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fulfillment;

use App\Http\Requests\Fulfillment\ConfirmDeliveryRequest;
use App\Http\Requests\Fulfillment\DispatchDeliveryRequest;
use App\Http\Requests\Fulfillment\FailDeliveryRequest;
use App\Http\Requests\Fulfillment\IssuePickListRequest;
use App\Http\Requests\Fulfillment\PackDeliveryRequest;
use App\Http\Requests\Fulfillment\RescheduleDeliveryRequest;
use App\Http\Requests\Fulfillment\StoreDeliveryNoteRequest;
use App\Modules\Fulfillment\Actions\ConfirmDelivery;
use App\Modules\Fulfillment\Actions\CreateDeliveryNote;
use App\Modules\Fulfillment\Actions\DispatchDelivery;
use App\Modules\Fulfillment\Actions\FailDelivery;
use App\Modules\Fulfillment\Actions\IssuePickList;
use App\Modules\Fulfillment\Actions\PackDelivery;
use App\Modules\Fulfillment\Actions\RescheduleDelivery;
use App\Modules\Fulfillment\Queries\GetDeliveryNoteQuery;
use App\Modules\Fulfillment\Queries\ListDeliveryNotesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeliveryNoteController
{
    public function __construct(
        private readonly ListDeliveryNotesQuery $listQuery,
        private readonly GetDeliveryNoteQuery $getQuery,
        private readonly CreateDeliveryNote $create,
        private readonly IssuePickList $issuePick,
        private readonly PackDelivery $pack,
        private readonly DispatchDelivery $dispatch,
        private readonly ConfirmDelivery $confirm,
        private readonly FailDelivery $fail,
        private readonly RescheduleDelivery $reschedule,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery->execute(
            status: $request->query('status'),
            salesOrderId: $request->query('salesOrderId'),
            limit: (int) $request->query('limit', 50),
            offset: (int) $request->query('offset', 0),
        );

        return response()->json($result);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->getQuery->execute($id));
    }

    public function store(StoreDeliveryNoteRequest $request): JsonResponse
    {
        $note = $this->create->execute($request->validated());

        return response()->json($note, 201);
    }

    public function pick(string $id, IssuePickListRequest $request): JsonResponse
    {
        return response()->json($this->issuePick->execute($id, $request->validated()));
    }

    public function pack(string $id, PackDeliveryRequest $request): JsonResponse
    {
        return response()->json($this->pack->execute($id, $request->validated()));
    }

    public function dispatch(string $id, DispatchDeliveryRequest $request): JsonResponse
    {
        return response()->json($this->dispatch->execute($id, $request->validated()));
    }

    public function confirm(string $id, ConfirmDeliveryRequest $request): JsonResponse
    {
        return response()->json($this->confirm->execute($id, $request->validated()));
    }

    public function fail(string $id, FailDeliveryRequest $request): JsonResponse
    {
        return response()->json($this->fail->execute($id, $request->validated()));
    }

    public function reschedule(string $id, RescheduleDeliveryRequest $request): JsonResponse
    {
        return response()->json($this->reschedule->execute($id, $request->validated()));
    }
}
