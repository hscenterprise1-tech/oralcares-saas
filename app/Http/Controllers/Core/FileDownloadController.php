<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileDownloadController extends Controller
{
    /**
     * Download a secure file from local storage using a signed URL.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        // 1. Verify the signed URL
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired download link.');
        }

        // 2. Extract parameters
        $path = base64_decode($request->query('path'));
        $disk = $request->query('disk', config('filesystems.default'));

        // 3. Verify file exists
        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'File not found.');
        }

        // 4. Return the file
        return Storage::disk($disk)->download($path);
    }
}
