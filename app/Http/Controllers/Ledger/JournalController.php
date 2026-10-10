<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ledger;

use App\Http\Requests\Ledger\StoreJournalRequest;
use App\Modules\Ledger\Actions\CreateManualJournal;
use App\Modules\Ledger\Actions\PostManualJournal;
use App\Modules\Ledger\Actions\ReverseJournal;
use App\Modules\Ledger\Queries\GetJournalQuery;
use App\Modules\Ledger\Queries\ListJournalsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JournalController
{
    public function __construct(
        private readonly ListJournalsQuery $listJournalsQuery,
        private readonly GetJournalQuery $getJournalQuery,
        private readonly CreateManualJournal $createManualJournal,
        private readonly PostManualJournal $postManualJournal,
        private readonly ReverseJournal $reverseJournal,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $result = $this->listJournalsQuery->execute([
            'status' => $request->query('status'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'sourceType' => $request->query('sourceType'),
            'q' => $request->query('q'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]);

        return response()->json($result);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->getJournalQuery->execute($id));
    }

    public function store(StoreJournalRequest $request): JsonResponse
    {
        $journal = $this->createManualJournal->execute($request->validated());

        return response()->json($journal, 201);
    }

    public function post(string $id): JsonResponse
    {
        $journal = $this->postManualJournal->execute($id);

        return response()->json($journal);
    }

    public function reverse(string $id): JsonResponse
    {
        $journal = $this->reverseJournal->execute($id);

        return response()->json($journal);
    }
}
