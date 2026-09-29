<?php

namespace App\Services\Core;

use App\Contracts\VirusScannerInterface;
use App\Exceptions\FileEngineException;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
     * Get a temporary URL for a file on S3.
     *
     * Files on local disks get no link at all. They used to be served by a
     * generic signed-URL route that handed any file on any disk to whoever
     * held the link, with no tenant or patient check. That route is gone:
     * a file from a clinic record is served by that record's own authorized
     * route (for example `clinic.patients.documents.download`).
     *
     * @param  string  $path  The file path
     * @param  string|null  $disk  The disk
     * @param  CarbonInterface|int  $expiration  Expiration time for the URL
     *
     * @throws FileEngineException for any disk other than S3
     */
    public function getUrl(string $path, ?string $disk = null, $expiration = null): string
    {
        $disk = $disk ?: config('filesystems.default');
        $expiration = $expiration ?: now()->addMinutes(15);

        if ($disk === 's3') {
            return Storage::disk('s3')->temporaryUrl($path, $expiration);
        }

        throw new FileEngineException("Files on the [{$disk}] disk have no shareable link; serve them through the owning record's authorized route.");
    }
}
