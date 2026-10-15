<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Domain;

/** Cargo event types recorded against a consignment. */
final class CargoEventType
{
    public const DEPARTED = 'Departed';

    public const TRANSSHIPMENT = 'Transshipment';

    public const PORT_ARRIVED = 'Port arrived';

    public const CUSTOMS_HOLD = 'Customs hold';

    public const DELAY_NOTICE = 'Delay notice';

    public const DAMAGE_REPORTED = 'Damage reported';

    public const PARTIAL_ARRIVAL = 'Partial arrival';

    public const BOOKING_ROLLED = 'Booking rolled';

    public const CUSTOMS_RELEASED = 'Customs released';

    public const DELIVERED = 'Delivered';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::DEPARTED,
            self::TRANSSHIPMENT,
            self::PORT_ARRIVED,
            self::CUSTOMS_HOLD,
            self::DELAY_NOTICE,
            self::DAMAGE_REPORTED,
            self::PARTIAL_ARRIVAL,
            self::BOOKING_ROLLED,
            self::CUSTOMS_RELEASED,
            self::DELIVERED,
        ];
    }
}
