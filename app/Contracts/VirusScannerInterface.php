<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface VirusScannerInterface
{
    /**
     * Scan the given uploaded file for viruses.
     *
     * @return bool True if the file is clean, false if malware is detected.
     */
    public function isClean(UploadedFile $file): bool;
}
