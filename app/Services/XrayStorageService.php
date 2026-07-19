<?php

namespace App\Services;

class XrayStorageService
{
    /**
     * Store raw file contents using storage abstraction layer.
     */
    public function store(string $contents, string $filename): string
    {
        // Abstracts future cloud object storage integration (e.g. S3 bucket URI)
        $uniqueName = uniqid().'_'.$filename;

        return "s3://oralcares-imaging/xrays/{$uniqueName}";
    }

    /**
     * Retrieve file contents from the abstract storage path.
     */
    public function retrieve(string $storagePath): string
    {
        return "mock_binary_contents_for_{$storagePath}";
    }
}
