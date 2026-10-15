<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Domain;

/** Consignment status values. */
final class ConsignmentStatus
{
    public const BOOKED = 'Booked';

    public const IN_TRANSIT = 'In transit';

    public const CUSTOMS_HOLD = 'Customs hold';

    public const ARRIVED = 'Arrived';

    public const RECEIVED = 'Received';

    public const ROLLED = 'Rolled';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::BOOKED,
            self::IN_TRANSIT,
            self::CUSTOMS_HOLD,
            self::ARRIVED,
            self::RECEIVED,
            self::ROLLED,
        ];
    }
}
