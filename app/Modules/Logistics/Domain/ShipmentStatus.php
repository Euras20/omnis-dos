<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Domain;

/** Shipment status values matching the frontend logistics board. */
final class ShipmentStatus
{
    public const BOOKED = 'Booked';

    public const IN_TRANSIT = 'In transit';

    public const CUSTOMS_HOLD = 'Customs hold';

    public const CLEARED = 'Cleared';

    public const PARTIALLY_RECEIVED = 'Partially received';

    public const RECEIVED = 'Received';

    public const CANCELLED = 'Cancelled';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::BOOKED,
            self::IN_TRANSIT,
            self::CUSTOMS_HOLD,
            self::CLEARED,
            self::PARTIALLY_RECEIVED,
            self::RECEIVED,
            self::CANCELLED,
        ];
    }
}
