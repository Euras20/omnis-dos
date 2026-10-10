<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

final class JournalStatus
{
    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const REVERSED = 'reversed';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DRAFT, self::POSTED, self::REVERSED];
    }
}
