<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Base for all OMNIS domain / application exceptions that map to the
 * canonical API error shape (11-error-handling-and-failure-isolation.md).
 *
 * Actions and shared kernel services throw these; the global exception
 * renderer turns them into JSON + status. Never embed HTTP concerns inside
 * domain Actions beyond the status code carried here.
 */
abstract class DomainException extends Exception
{
    /**
     * Stable machine-readable error code (e.g. "period_locked").
     */
    protected string $errorCode;

    /**
     * HTTP status that the API should return.
     */
    protected int $status = 422;

    /**
     * Optional field-level messages for validation_error (path => messages).
     *
     * @var array<string, list<string>|string>|null
     */
    protected ?array $fields = null;

    public function __construct(
        string $message,
        ?string $errorCode = null,
        int $status = 422,
        ?array $fields = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode ?? $this->defaultErrorCode();
        $this->status = $status;
        $this->fields = $fields;
    }

    abstract protected function defaultErrorCode(): string;

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, list<string>|string>|null
     */
    public function fields(): ?array
    {
        return $this->fields;
    }

    /**
     * Payload for the canonical error response body (without requestId).
     *
     * @return array{error: string, message: string, fields?: array}
     */
    public function toErrorArray(): array
    {
        $payload = [
            'error' => $this->errorCode(),
            'message' => $this->getMessage(),
        ];

        if ($this->fields !== null) {
            $payload['fields'] = $this->fields;
        }

        return $payload;
    }
}
