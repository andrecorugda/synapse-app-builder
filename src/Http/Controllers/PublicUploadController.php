<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Services\MediaLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Public-facing single-image upload endpoint for forms rendered by the
 * page-builder. Gated by default: only authenticated users (either a signed-in
 * pb app-user OR a panel/host-app user) may upload. A deployer may open the
 * endpoint to anonymous uploads by setting
 * `ai-page-builder.uploads.allow_anonymous` (or env AI_PAGE_BUILDER_ALLOW_ANON_UPLOADS)
 * to true — only do this when you have external controls (e.g. WAF, IP allow-list).
 *
 * Response shape on success: { "url": "<public url>" } — HTTP 201.
 * Rejection shape:           { "message": "..." }      — HTTP 403 | 422.
 */
class PublicUploadController
{
    public function __invoke(Request $request, MediaLibrary $library): JsonResponse
    {
        // --- Auth gate (default: on) ---
        $allowAnonymous = (bool) config('ai-page-builder.uploads.allow_anonymous', false);

        if (! $allowAnonymous) {
            // Accept either the pb guard (built-app end-user) or the default web
            // guard (host-app / panel user). Auth::check() uses the default guard;
            // we also probe the pb guard explicitly.
            $pbGuard = (string) config('ai-page-builder.auth.guard', 'pb');
            $authenticated = Auth::check() || Auth::guard($pbGuard)->check();

            if (! $authenticated) {
                return response()->json(
                    ['message' => 'Unauthenticated. Login to upload files.'],
                    403,
                );
            }
        }

        // --- Validation (always enforced) ---
        $maxKb = (int) config('ai-page-builder.uploads.max_kb', 5120);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:'.$maxKb],
        ]);

        /** @var UploadedFile $uploaded */
        $uploaded = $request->file('file');

        // Derive extension from validated mime type — never trust the client name.
        $ext = $this->extensionFromMime((string) $uploaded->getMimeType());
        $safeFile = $this->wrapWithRandomName($uploaded, $ext);

        // Resolve the current user id for the audit trail (null when anonymous).
        $userId = null;
        if (Auth::check()) {
            $id = Auth::user()?->getAuthIdentifier();
            $userId = is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
        } elseif (Auth::guard((string) config('ai-page-builder.auth.guard', 'pb'))->check()) {
            $id = Auth::guard((string) config('ai-page-builder.auth.guard', 'pb'))->user()?->getAuthIdentifier();
            $userId = is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
        }

        // --- Store via the shared MediaLibrary service ---
        $item = $library->store($safeFile, $userId);

        return response()->json(['url' => $item->url()], 201);
    }

    /**
     * Map validated MIME type to a safe file extension. Falls back to the
     * UploadedFile guess (which probes the file header) and ultimately to 'bin'.
     */
    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    /**
     * Return a copy of the uploaded file renamed to a random basename so that
     * the client-supplied filename never reaches the storage layer.
     */
    private function wrapWithRandomName(UploadedFile $file, string $ext): UploadedFile
    {
        $randomName = Str::random(28).'.'.$ext;

        return new UploadedFile(
            path: $file->getRealPath(),
            originalName: $randomName,
            mimeType: $file->getMimeType(),
            error: null,
            test: true,
        );
    }
}
