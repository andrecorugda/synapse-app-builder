<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Services\MediaLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController
{
    /**
     * Accept one or more uploaded files (GrapesJS posts `files[]`) and return
     * them in the asset-manager's expected shape: { data: [ {src, ...} ] }.
     */
    public function upload(Request $request, MediaLibrary $library): JsonResponse
    {
        $maxKb = (int) config('ai-page-builder.media.max_kb', 8192);
        $accept = (array) config('ai-page-builder.media.accept', []);
        $mimeRule = $accept === [] ? 'file' : 'mimetypes:'.implode(',', $accept);

        $request->validate([
            'files' => ['required'],
            'files.*' => ['file', $mimeRule, 'max:'.$maxKb],
        ]);

        $userId = $request->user()?->getAuthIdentifier();
        $userId = is_int($userId) ? $userId : (is_numeric($userId) ? (int) $userId : null);

        $files = $request->file('files');
        $files = is_array($files) ? $files : [$files];

        $data = [];
        foreach ($files as $file) {
            $data[] = $library->store($file, $userId)->toAsset();
        }

        return response()->json(['data' => $data]);
    }
}
