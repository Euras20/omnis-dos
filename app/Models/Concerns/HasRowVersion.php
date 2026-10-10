<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Shared\Concurrency\RowVersion;
use Illuminate\Database\Eloquent\Model;

/**
 * Optimistic concurrency via integer row_version column.
 *
 * Prefer Shared RowVersion helpers from Actions:
 *   RowVersion::assertMatches($model, $incoming);
 *   RowVersion::bump($model);
 *
 * The boot hook still increments on any Eloquent update as a safety net.
 */
trait HasRowVersion
{
    public static function bootHasRowVersion(): void
    {
        static::updating(function (Model $model): void {
            // Only auto-bump when the attribute was not already set by the caller
            // (e.g. RowVersion::bump already ran).
            if (! $model->isDirty('row_version')) {
                $current = (int) $model->getOriginal('row_version');
                $model->setAttribute('row_version', $current + 1);
            }
        });
    }

    /**
     * Assert the client's expected version matches the current row.
     *
     * @throws \App\Exceptions\StaleRevisionException
     */
    public function assertRowVersion(int|string|null $expected): void
    {
        RowVersion::assertMatches($this, $expected);
    }
}
