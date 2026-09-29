<?php

namespace App\Services\Core;

use App\Contracts\VirusScannerInterface;
use App\Exceptions\FileEngineException;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class FileEngine
{
    public function __construct(
        protected VirusScannerInterface $scanner
    ) {}

    /**
     * Upload a file securely scoped to a tenant.
     *
     * @param  UploadedFile  $file  The uploaded file
     * @param  Organization  $organization  The organization to scope the file to
     * @param  string  $module  The module directory (e.g. 'xrays', 'agreements')
     * @param  string  $disk  The disk to use ('local' or 's3'). Defaults to the default configured disk.
     * @return string The stored file path
     *
     * @throws FileEngineException
     */
    public function upload(UploadedFile $file, Organization $organization, string $module, ?string $disk = null): string
    {
        $disk = $disk ?: config('filesystems.default');

        // 1. Enforce size limits (License Engine Hook - stubbed to 50MB for now, but will connect to License)
        $maxSizeBytes = 50 * 1024 * 1024; // 50MB
        if ($file->getSize() > $maxSizeBytes) {
            throw new FileEngineException('File exceeds the maximum allowed size of 50MB.');
        }

        // 2. Virus Scanning
        if (! $this->scanner->isClean($file)) {
            throw new FileEngineException('File upload blocked: Malware detected.');
        }

        // 3. Enforce Tenant Isolation in Path
        // Path format: {organization_id}/{module}/{uuid}.{extension}
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = $organization->id.'/'.$module;

        $storedPath = $file->storeAs($path, $filename, ['disk' => $disk]);

        if (! $storedPath) {
            throw new FileEngineException('Failed to store the file.');
        }

        return $storedPath;
    }

    /**
     * Delete a file.
     */
    public function delete(string $path, ?string $disk = null): bool
    {
        $disk = $disk ?: config('filesystems.default');

        return Storage::disk($disk)->delete($path);
    }

    /**
     * Get a secure URL for the file.
     * For S3, it generates a temporary expiring URL.
     * For local storage, it generates a signed URL to a secure download route.
     *
     * @param  string  $path  The file path
     * @param  string|null  $disk  The disk ('local' or 's3')
     * @param  CarbonInterface|int  $expiration  Expiration time for the URL
     */
    public function getUrl(string $path, ?string $disk = null, $expiration = null): string
    {
        $disk = $disk ?: config('filesystems.default');
        $expiration = $expiration ?: now()->addMinutes(15);

        if ($disk === 's3') {
            return Storage::disk('s3')->temporaryUrl($path, $expiration);
        }

        // For local storage, route through our secure controller using a signed URL
        return URL::temporarySignedRoute(
            'core.files.download',
            $expiration,
            ['path' => base64_encode($path), 'disk' => $disk]
        );
    }
}
