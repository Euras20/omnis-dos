<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain;

/**
 * Incoterms accepted on quotations and customer delivery terms.
 */
final class DeliveryType
{
    public const ALL = ['EXW', 'FCA', 'FAS', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

    public static function isKnown(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return in_array(strtoupper($value), self::ALL, true);
    }

    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = strtoupper(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }
}
