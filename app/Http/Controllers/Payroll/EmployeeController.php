<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Payroll\Actions\CreateEmployee;
use App\Shared\Company\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmployeeController extends Controller
{
    public function index(CompanyContext $companyContext): JsonResponse
    {
        $rows = Employee::query()
            ->where('company_id', $companyContext->companyId())
            ->orderBy('employee_no')
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'employeeNo' => $e->employee_no,
                'firstName' => $e->first_name,
                'lastName' => $e->last_name,
                'hireDate' => $e->hire_date?->format('Y-m-d'),
                'basicMinor' => (int) $e->basic_minor,
                'department' => $e->department,
                'jobTitle' => $e->job_title,
                'isActive' => (bool) $e->is_active,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, CreateEmployee $action): JsonResponse
    {
        $result = $action->execute($request->all());

        return response()->json($result, 201);
    }
}
