<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Generic 422 carrier for business rules that do not have a dedicated subclass.
 * Prefer specific subclasses (InsufficientQuantity, AlreadyPosted, …) when the
 * frontend or OpenAPI contract names the code.
 */
class BusinessRuleException extends DomainException
{
    public function __construct(
        string $message,
        string $errorCode = 'business_rule',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 422, null, $previous);
    }

    protected function defaultErrorCode(): string
    {
        return 'business_rule';
    }
}
