<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persistent store for Idempotency-Key outcomes.
 *
 * Table: idempotency_key (company_id, key, request_hash, status_code, response, …)
 * Created in the foundation migration group (Step 14.4).
 *
 * Behaviour (03-api.md):
 * - Same key + same body hash → return stored response + Idempotent-Replay: true
 * - Same key + different body  → 409 idempotency_mismatch
 * - TTL controlled by config/omnis.php (default 48 h)
 */
final class IdempotencyStore
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * Look up an existing key. Returns null when the key is unknown or expired.
     *
     * @return array{request_hash: string, status_code: int, response: array|null, created_at: string}|null
     */
    public function find(string $key): ?array
    {
        $row = DB::table('idempotency_key')
            ->where('company_id', $this->companyContext->companyId())
            ->where('key', $key)
            ->where('created_at', '>=', now()->subHours(
                (int) config('omnis.idempotency.ttl_hours', 48)
            ))
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'request_hash' => (string) $row->request_hash,
            'status_code' => (int) $row->status_code,
            'response' => $row->response !== null
                ? json_decode((string) $row->response, true, 512, JSON_THROW_ON_ERROR)
                : null,
            'created_at' => (string) $row->created_at,
        ];
    }

    /**
     * Persist a successful (or failed) command outcome under the given key.
     * Must be called inside the same DB transaction as the business write
     * when the command succeeded, so that a rolled-back command never
     * leaves a stale success record.
     *
     * @param  array<string, mixed>|null  $responseBody
     */
    public function store(
        string $key,
        string $requestHash,
        int $statusCode,
        ?array $responseBody,
    ): void {
        DB::table('idempotency_key')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->companyContext->companyId(),
            'key' => $key,
            'request_hash' => $requestHash,
            'status_code' => $statusCode,
            'response' => $responseBody !== null
                ? json_encode($responseBody, JSON_THROW_ON_ERROR)
                : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Canonical hash of the request body for mismatch detection.
     * Empty body (e.g. DELETE) hashes to a stable constant.
     */
    public function hashRequestBody(?string $rawBody): string
    {
        $payload = $rawBody ?? '';

        return hash('sha256', $payload);
    }
}
