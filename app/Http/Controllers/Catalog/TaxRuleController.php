<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Requests\Catalog\StoreTaxRuleRequest;
use App\Modules\Catalog\Actions\CreateTaxRuleVersion;
use App\Modules\Catalog\Queries\ListTaxRulesQuery;
use App\Modules\Catalog\Queries\ListWhtRulesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaxRuleController
{
    public function __construct(
        private readonly ListTaxRulesQuery $listTaxRulesQuery,
        private readonly ListWhtRulesQuery $listWhtRulesQuery,
        private readonly CreateTaxRuleVersion $createTaxRuleVersion,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listTaxRulesQuery->execute([
            'date' => $request->query('date'),
        ]));
    }

    public function store(StoreTaxRuleRequest $request): JsonResponse
    {
        $rule = $this->createTaxRuleVersion->execute($request->validated());

        return response()->json($rule, 201);
    }

    public function wht(Request $request): JsonResponse
    {
        return response()->json($this->listWhtRulesQuery->execute([
            'date' => $request->query('date'),
        ]));
    }
}
