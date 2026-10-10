<?php

declare(strict_types=1);

namespace App\Shared\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast: BIGINT minor-unit column ↔ Money value object.
 *
 * Usage on a model:
 *   protected function casts(): array
 *   {
 *       return ['amount_minor' => MoneyCast::class];
 *   }
 *
 * Currency is fixed to the company default (GHS) unless a companion
 * currency column is also cast; this cast assumes single-currency storage.
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly string $currency = 'GHS',
    ) {
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException(
                "MoneyCast expects integer minor units for [{$key}], got ".get_debug_type($value)
            );
        }

        return Money::of((int) $value, $this->currency);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            if ($value->currency() !== $this->currency) {
                throw new InvalidArgumentException(
                    "Money currency {$value->currency()} does not match cast currency {$this->currency}"
                );
            }

            return $value->amountMinor();
        }

        if (is_int($value)) {
            return $value;
        }

        throw new InvalidArgumentException(
            'MoneyCast set() accepts Money or int, got '.get_debug_type($value)
        );
    }
}
