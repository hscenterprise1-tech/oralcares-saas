<?php

use App\Models\Lead;
use App\Models\User;
use App\Services\CRM\DealDetailService;
use App\UserType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = grantCrmPermissions(User::factory()->create(['user_type' => UserType::CompanyUser]));
    $this->lead = Lead::factory()->create();
    Storage::fake('local');
});

it('can upload a document to a lead', function () {
    $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

    $response = $this->actingAs($this->user)
        ->post(route('company.crm.leads.documents.store', $this->lead), [
            'document' => $file,
        ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('crm_documents', [
        'documentable_id' => $this->lead->id,
        'documentable_type' => $this->lead->getMorphClass(),
        'file_name' => 'document.pdf',
    ]);
});

it('can download a document attached to a lead', function () {
    $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');
    $path = $file->store('crm_documents', 'local');

    $document = $this->lead->documents()->create([
        'file_name' => 'document.pdf',
        'file_path' => $path,
        'file_size_bytes' => 1024,
        'mime_type' => 'application/pdf',
        'uploaded_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('company.crm.leads.documents.download', [$this->lead, $document]));

    $response->assertDownload('document.pdf');
});

/**
 * CRM completion pass — the deal page's Attachments list existed and worked
 * (upload, download) but never showed a delete control despite the endpoint
 * above being live and tested; this file's `size` field also always read null
 * because `DealDetailService::files()` mapped a `file_size` attribute the
 * model has never had. Both closed together, so this pins the size fix.
 */
it('reports the real byte size of an attached file on the deal payload', function () {
    $this->lead->documents()->create([
        'file_name' => 'document.pdf',
        'file_path' => 'crm_documents/document.pdf',
        'file_size_bytes' => 204800,
        'mime_type' => 'application/pdf',
        'uploaded_by' => $this->user->id,
    ]);

    $files = app(DealDetailService::class)->files($this->lead);

    expect($files)->toHaveCount(1)
        ->and($files[0]['size'])->toBe(204800);
});

it('can delete a document attached to a lead', function () {
    $document = $this->lead->documents()->create([
        'file_name' => 'document.pdf',
        'file_path' => 'crm_documents/document.pdf',
        'file_size_bytes' => 1024,
        'mime_type' => 'application/pdf',
        'uploaded_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->delete(route('company.crm.leads.documents.destroy', [$this->lead, $document]));

    $response->assertSessionHas('success');

    $this->assertSoftDeleted('crm_documents', [
        'id' => $document->id,
    ]);
});
