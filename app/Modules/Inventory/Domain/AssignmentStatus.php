<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

final class AssignmentStatus
{
    public const PENDING = 'pending';

    public const ASSIGNED = 'assigned';

    public const REJECTED = 'rejected';

    public const RELEASED = 'released';

    public const DELIVERED = 'delivered';

    /**
     * @return list<string>
     */
    public static function open(): array
    {
        return [self::PENDING, self::ASSIGNED];
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::open(), true);
    }
}

