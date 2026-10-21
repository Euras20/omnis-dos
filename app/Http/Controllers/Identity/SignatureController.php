<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Exceptions\NotFoundException;
use App\Models\AppUser;
use App\Modules\Identity\Actions\DeleteSignature;
use App\Modules\Identity\Actions\UploadSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SignatureController
{
    public function store(Request $request, UploadSignature $action): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:1024'],
        ]);

        /** @var AppUser $user */
        $user = $request->user();

        return response()->json($action->execute($user, $request->file('file')), 201);
    }

    public function show(Request $request): StreamedResponse
    {
        return $this->stream($this->fresh($request));
    }

    public function showForUser(string $id): StreamedResponse
    {
        $user = AppUser::query()->find($id);
        if (! $user) {
            throw new NotFoundException('User not found.');
        }

        return $this->stream($user);
    }

    public function destroy(Request $request, DeleteSignature $action): JsonResponse
    {
        $action->execute($this->fresh($request));

        return response()->json(['hasSignature' => false]);
    }

    /**
     * The auth principal is hydrated without every column. Reload so signature_path is current.
     */
    private function fresh(Request $request): AppUser
    {
        /** @var AppUser $principal */
        $principal = $request->user();

        $user = AppUser::query()->find($principal->id);
        if (! $user) {
            throw new NotFoundException('User not found.');
        }

        return $user;
    }

    private function stream(AppUser $user): StreamedResponse
    {
        if (! $user->signature_path || ! Storage::disk('local')->exists($user->signature_path)) {
            throw new NotFoundException('No signature on file.');
        }

        $mime = match (pathinfo($user->signature_path, PATHINFO_EXTENSION)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        return Storage::disk('local')->response($user->signature_path, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
