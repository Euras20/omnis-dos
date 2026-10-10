<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Aggregate or resource missing for the caller's company (or soft-hidden).
 * HTTP 404 not_found.
 */
final class NotFoundException extends DomainException
{
    public function __construct(
        string $message = 'The requested resource was not found.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'not_found', 404, null, $previous);
    }

    protected function defaultErrorCode(): string
    {
        return 'not_found';
    }

    public static function resource(string $resource, string|int $id): self
    {
        return new self("{$resource} [{$id}] was not found.");
    }
}
