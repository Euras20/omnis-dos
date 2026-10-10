<?php

declare(strict_types=1);

namespace App\Shared\Company;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Eloquent scope that forces company_id = current CompanyContext.
 *
 * Apply to every business model that carries a company_id column.
 * Identity models that are company-scoped also use this scope.
 *
 * Usage:
 *   protected static function booted(): void
 *   {
 *       static::addGlobalScope(new CompanyScope);
 *   }
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CompanyContext::class);

        if (! $context->isResolved()) {
            // During migrations, seeders, or early bootstrap the context may
            // not yet exist. Callers that need unscoped access must use
            // withoutGlobalScope(CompanyScope::class) explicitly.
            return;
        }

        $builder->where(
            $model->getTable().'.company_id',
            $context->companyId()
        );
    }
}
