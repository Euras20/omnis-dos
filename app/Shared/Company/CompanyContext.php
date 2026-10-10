<?php

declare(strict_types=1);

namespace App\Shared\Company;

use RuntimeException;

/**
 * Request-scoped company context.
 *
 * Resolved by middleware from the authenticated principal (never from a client
 * body field on ordinary requests). Every business query and command must
 * obtain the company_id from this context.
 *
 * Bound as a singleton in the container for the lifetime of the request.
 */
final class CompanyContext
{
    private ?string $companyId = null;

    private ?string $userId = null;

    private ?string $requestId = null;

    private bool $resolved = false;

    public function set(string $companyId, ?string $userId = null, ?string $requestId = null): void
    {
        if ($companyId === '') {
            throw new RuntimeException('company_id must not be empty.');
        }

        $this->companyId = $companyId;
        $this->userId = $userId;
        $this->requestId = $requestId;
        $this->resolved = true;
    }

    public function companyId(): string
    {
        $this->assertResolved();

        return $this->companyId;
    }

    public function userId(): ?string
    {
        $this->assertResolved();

        return $this->userId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * Clear context (useful in tests and long-running worker jobs that switch companies).
     */
    public function clear(): void
    {
        $this->companyId = null;
        $this->userId = null;
        $this->requestId = null;
        $this->resolved = false;
    }

    private function assertResolved(): void
    {
        if (! $this->resolved || $this->companyId === null) {
            throw new RuntimeException(
                'CompanyContext has not been resolved. Ensure CompanyContext middleware ran.'
            );
        }
    }
}
