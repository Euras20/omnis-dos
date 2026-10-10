<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Shared\Company\CompanyContext;
use App\Shared\Company\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Apply to every business model that carries a company_id column.
 *
 * - Adds CompanyScope global scope (filters by resolved CompanyContext).
 * - Auto-fills company_id on creating when context is resolved.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            $context = app(CompanyContext::class);
            if ($context->isResolved()) {
                $model->setAttribute('company_id', $context->companyId());
            }
        });
    }
}
