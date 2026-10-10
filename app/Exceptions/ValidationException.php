<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Request body / query fails schema or value-object rules.
 * HTTP 400 validation_error (always include fields when available).
 */
final class ValidationException extends DomainException
{
    /**
     * @param  array<string, list<string>|string>  $fields
     */
    public function __construct(
        string $message = 'The given data was invalid.',
        array $fields = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            'validation_error',
            400,
            $fields === [] ? null : $fields,
            $previous,
        );
    }

    protected function defaultErrorCode(): string
    {
        return 'validation_error';
    }

    /**
     * Build from Laravel's validation exception or a plain message bag.
     *
     * @param  array<string, list<string>|string>  $errors
     */
    public static function fromFields(array $errors, string $message = 'The given data was invalid.'): self
    {
        return new self($message, $errors);
    }
}
