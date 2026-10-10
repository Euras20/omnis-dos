<?php

declare(strict_types=1);

namespace App\Shared\Concurrency;

use App\Exceptions\StaleRevisionException;
use Illuminate\Database\Eloquent\Model;

/**
 * Optimistic concurrency helper for editable masters that carry row_version.
 *
 * Clients send the last-seen rowVersion on PATCH; the server compares and
 * increments on success. A mismatch returns 409 stale_revision.
 *
 * Usage inside an Action:
 *   RowVersion::assertMatches($model, $incomingVersion);
 *   // … mutate …
 *   RowVersion::bump($model);
 *   $model->save();
 */
final class RowVersion
{
    public static function assertMatches(Model $model, int|string|null $incomingVersion): void
    {
        $current = (int) $model->getAttribute('row_version');

        if ($incomingVersion === null || (int) $incomingVersion !== $current) {
            throw new StaleRevisionException(
                sprintf(
                    'The resource was modified by another request (expected row_version %d). Refresh and try again.',
                    $current
                )
            );
        }
    }

    public static function bump(Model $model): void
    {
        $current = (int) $model->getAttribute('row_version');
        $model->setAttribute('row_version', $current + 1);
    }

    /**
     * Atomic update that fails when the version no longer matches.
     * Prefer this when the Action only touches a few columns.
     *
     * @param  array<string, mixed>  $attributes
     * @return bool  true when the row was updated
     */
    public static function updateIfMatch(Model $model, int $expectedVersion, array $attributes): bool
    {
        $attributes['row_version'] = $expectedVersion + 1;

        $affected = $model->newQuery()
            ->where($model->getKeyName(), $model->getKey())
            ->where('row_version', $expectedVersion)
            ->update($attributes);

        if ($affected === 1) {
            $model->setAttribute('row_version', $expectedVersion + 1);
            foreach ($attributes as $key => $value) {
                if ($key !== 'row_version') {
                    $model->setAttribute($key, $value);
                }
            }

            return true;
        }

        return false;
    }
}
