<?php

use App\Contracts\VirusScannerInterface;
use App\Exceptions\FileEngineException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Core\FileEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('s3');

    $this->org = Organization::factory()->create();
    $this->scanner = Mockery::mock(VirusScannerInterface::class);

    // Bind the mock to the container
    app()->instance(VirusScannerInterface::class, $this->scanner);

    $this->engine = app(FileEngine::class);

    config(['filesystems.default' => 'local']);
});

it('uploads a file successfully and scopes it to the organization path', function () {
    $this->scanner->shouldReceive('isClean')->once()->andReturn(true);

    $file = UploadedFile::fake()->create('test.jpg', 10);

    $path = $this->engine->upload($file, $this->org, 'xrays', 'local');

    expect(Str::startsWith($path, $this->org->id.'/xrays/'))->toBeTrue();
    Storage::disk('local')->assertExists($path);
});

it('throws FileEngineException if file exceeds size limit', function () {
    // 51MB file
    $file = UploadedFile::fake()->create('huge.pdf', 51 * 1024);

    $this->engine->upload($file, $this->org, 'agreements', 'local');
})->throws(FileEngineException::class, 'maximum allowed size');

it('throws FileEngineException if virus scanner detects malware', function () {
    $this->scanner->shouldReceive('isClean')->once()->andReturn(false);

    $file = UploadedFile::fake()->create('malware.exe', 10);

    $this->engine->upload($file, $this->org, 'attachments', 'local');
})->throws(FileEngineException::class, 'Malware detected');

it('deletes a file', function () {
    $path = 'dummy/path/test.txt';
    Storage::disk('local')->put($path, 'content');
    Storage::disk('local')->assertExists($path);

    $this->engine->delete($path, 'local');

    Storage::disk('local')->assertMissing($path);
});

it('refuses to mint a shareable link for a file on a local disk', function () {
    $this->engine->getUrl('test/path.jpg', 'local');
})->throws(FileEngineException::class, 'no shareable link');

it('generates a temporary URL for s3 disks', function () {
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk = Mockery::mock());
    $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.aws.com/temp-url');

    $url = $this->engine->getUrl('test/path.jpg', 's3');

    expect($url)->toBe('https://s3.aws.com/temp-url');
});

it('has no generic file download route: a signed path on any disk is not served', function () {
    $path = 'test/secure.txt';
    Storage::disk('local')->put($path, 'secret content');

    expect(Route::has('core.files.download'))->toBeFalse();

    $this->actingAs(User::factory()->create())
        ->get('/files/download?path='.base64_encode($path).'&disk=local')
        ->assertNotFound();
});
