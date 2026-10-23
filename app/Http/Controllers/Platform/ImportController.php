<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\RunTemplateImport;
use App\Modules\Platform\Domain\ImportDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImportController extends Controller
{
    public function templates(): JsonResponse
    {
        $list = [];
        foreach (ImportDefinitions::all() as $type => $def) {
            $list[] = [
                'type' => $type,
                'label' => $def['label'],
                'permission' => $def['permission'],
                'columns' => array_map(fn ($c) => [
                    'name' => $c['name'],
                    'required' => $c['required'],
                    'type' => $c['type'],
                    'hint' => $c['hint'],
                ], $def['columns']),
            ];
        }

        return response()->json(['data' => $list]);
    }

    public function template(string $type): Response
    {
        $def = ImportDefinitions::get($type);
        if ($def === null) {
            return response()->json(['error' => 'unknown_import_type', 'message' => 'Unknown import type.'], 404);
        }

        $headers = array_column($def['columns'], 'name');
        // Blank data row only. Hints live in the JSON template list, not in the CSV,
        // so a user who downloads and re-uploads the template cannot insert the hint text.
        $blank = array_fill(0, count($headers), '');
        $csv = implode(',', $headers)."\n".implode(',', $blank)."\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$type.'-template.csv"',
        ]);
    }

    public function store(string $type, Request $request, RunTemplateImport $action): JsonResponse
    {
        return response()->json($action->execute($type, $request->all()), 201);
    }
}
