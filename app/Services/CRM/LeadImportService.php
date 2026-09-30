<?php

namespace App\Services\CRM;

use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Services\Marketing\PhoneNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Bulk lead creation from a CSV file.
 *
 * A lead could previously only ever be created one at a time through the
 * discovery form. This is deliberately the smallest useful version of that —
 * a fixed CSV header, no column-mapping UI, no XLSX support — rather than a
 * second import platform beside Marketing's. It reuses that platform's two
 * real decisions: `LeadWorkspaceService::findDuplicateLead()` is the same
 * phone/email/company-name match the create form already warns on, so an
 * import cannot invent a different definition of "this lead already exists";
 * and every row a file contained is recorded (`LeadImportRow`), mirroring
 * `marketing_contact_import_rows`, so a batch page can answer "what happened
 * to the people in this file" rather than only a total.
 *
 * Required columns: company_name, contact_name, phone, city,
 * healthcare_specialty. Optional: email.
 */
class LeadImportService
{
    public function __construct(protected LeadWorkspaceService $workspace) {}

    public const REQUIRED_COLUMNS = ['company_name', 'contact_name', 'phone', 'city', 'healthcare_specialty'];

    /**
     * Parse the file and classify every row — valid, a duplicate (in-file or
     * already on record), or invalid — without writing anything. What the
     * upload modal shows before anyone commits to it.
     *
     * @return array<string, mixed>
     */
    public function previewImport(UploadedFile $file, ?int $organizationId): array
    {
        $rawRows = $this->parseCsv($file);

        if (empty($rawRows)) {
            return $this->emptyPreview();
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rawRows));
        $columnIndex = array_flip($headers);

        $rows = [];
        $seenPhones = [];
        $counts = ['valid' => 0, 'duplicate' => 0, 'invalid' => 0];

        foreach ($rawRows as $i => $rawRow) {
            $row = $this->classifyRow($rawRow, $columnIndex, $organizationId, $seenPhones);
            $row['row_number'] = $i + 2; // 1-based, plus the header row
            $counts[$row['status']]++;
            $rows[] = $row;
        }

        return [
            'total_rows' => count($rawRows),
            'valid_rows' => $counts['valid'],
            'duplicate_rows' => $counts['duplicate'],
            'invalid_rows' => $counts['invalid'],
            'columns' => $headers,
            'missing_columns' => array_values(array_diff(self::REQUIRED_COLUMNS, $headers)),
            'preview' => array_slice($rows, 0, 20),
            'rows' => $rows,
        ];
    }

    /**
     * Create a lead for every row the preview marked valid. Duplicate and
     * invalid rows are recorded but never create a lead — the file said they
     * existed, and that fact belongs on the batch, not silently dropped.
     *
     * @param  array<int, array<string, mixed>>  $rows  The `rows` a prior previewImport() returned.
     * @return array{imported_count: int, duplicate_count: int, invalid_count: int}
     */
    public function processImport(LeadImport $import, array $rows, ?int $organizationId, ?User $user): array
    {
        $imported = 0;
        $duplicate = 0;
        $invalid = 0;
        $importRows = [];
        $seenPhones = [];

        DB::transaction(function () use ($import, $rows, $organizationId, $user, &$imported, &$duplicate, &$invalid, &$importRows, &$seenPhones) {
            foreach ($rows as $row) {
                // Re-classified rather than trusting the preview's own
                // verdict verbatim — time may have passed since the preview
                // ran, and a lead matching this row could have been created
                // by somebody else in between.
                $fresh = $this->classifyKnownRow($row, $organizationId, $seenPhones);

                if ($fresh['status'] === 'invalid') {
                    $invalid++;
                    $importRows[] = $this->importRowPayload($import->id, null, $row, 'invalid', $fresh['reason'] ?? null);

                    continue;
                }

                if ($fresh['status'] === 'duplicate') {
                    $duplicate++;
                    $importRows[] = $this->importRowPayload($import->id, null, $row, 'duplicate', $fresh['reason'] ?? null);

                    continue;
                }

                $result = $this->workspace->createLead([
                    'company_name' => $row['company_name'],
                    'contact_name' => $row['contact_name'],
                    'phone' => $row['phone'],
                    'city' => $row['city'],
                    'email' => empty($row['email']) ? null : $row['email'],
                    'healthcare_specialties' => [$row['healthcare_specialty']],
                    'organization_id' => $organizationId,
                    'source' => 'import',
                ], $user);

                $imported++;
                $seenPhones[PhoneNormalizer::normalize($row['phone'])] = true;
                $importRows[] = $this->importRowPayload($import->id, $result['lead']->id, $row, 'created', null);
            }

            foreach (array_chunk($importRows, 500) as $chunk) {
                LeadImportRow::insert($chunk);
            }
        });

        return ['imported_count' => $imported, 'duplicate_count' => $duplicate, 'invalid_count' => $invalid];
    }

    /**
     * One raw CSV row's verdict: valid, duplicate (in this file or already
     * on record), or invalid (missing a required field).
     *
     * @param  array<int, string>  $rawRow
     * @param  array<string, int>  $columnIndex
     * @param  array<string, bool>  $seenPhones  Normalised phones already claimed earlier in this run.
     * @return array<string, mixed>
     */
    protected function classifyRow(array $rawRow, array $columnIndex, ?int $organizationId, array &$seenPhones): array
    {
        $get = fn (string $column) => trim((string) ($rawRow[$columnIndex[$column] ?? -1] ?? ''));

        return $this->classify([
            'company_name' => $get('company_name'),
            'contact_name' => $get('contact_name'),
            'phone' => $get('phone'),
            'city' => $get('city'),
            'healthcare_specialty' => $get('healthcare_specialty'),
            'email' => $get('email'),
        ], $organizationId, $seenPhones);
    }

    /**
     * The same verdict, re-derived from a preview row's already-extracted
     * fields rather than raw CSV cells — used at process time, so a lead
     * created by somebody else between preview and process is still caught.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, bool>  $seenPhones
     * @return array<string, mixed>
     */
    protected function classifyKnownRow(array $row, ?int $organizationId, array &$seenPhones): array
    {
        return $this->classify([
            'company_name' => $row['company_name'] ?? '',
            'contact_name' => $row['contact_name'] ?? '',
            'phone' => $row['phone'] ?? '',
            'city' => $row['city'] ?? '',
            'healthcare_specialty' => $row['healthcare_specialty'] ?? '',
            'email' => $row['email'] ?? '',
        ], $organizationId, $seenPhones);
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, bool>  $seenPhones
     * @return array<string, mixed>
     */
    protected function classify(array $fields, ?int $organizationId, array &$seenPhones): array
    {
        [$companyName, $contactName, $phone, $city, $specialty, $email] = [
            $fields['company_name'], $fields['contact_name'], $fields['phone'],
            $fields['city'], $fields['healthcare_specialty'], $fields['email'],
        ];

        if ($companyName === '' || $contactName === '' || $phone === '' || $city === '' || $specialty === '') {
            return $this->rowResult('invalid', 'Missing a required field — company name, contact name, phone, city and specialty are all required.', $fields);
        }

        $normalizedPhone = PhoneNormalizer::normalize($phone);

        if ($normalizedPhone && isset($seenPhones[$normalizedPhone])) {
            return $this->rowResult('duplicate', 'Duplicate phone number inside the uploaded file.', $fields);
        }

        $existing = $this->workspace->findDuplicateLead($organizationId, $phone, $email ?: null, null, $companyName);

        if ($existing) {
            $seenPhones[$normalizedPhone] = true;

            return $this->rowResult('duplicate', "Already on file as {$existing->company_name}.", $fields);
        }

        $seenPhones[$normalizedPhone] = true;

        return $this->rowResult('valid', null, $fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function rowResult(string $status, ?string $reason, array $fields): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'company_name' => $fields['company_name'],
            'contact_name' => $fields['contact_name'],
            'phone' => $fields['phone'],
            'city' => $fields['city'],
            'healthcare_specialty' => $fields['healthcare_specialty'],
            'email' => $fields['email'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function importRowPayload(int $importId, ?int $leadId, array $row, string $outcome, ?string $reason): array
    {
        $now = now();

        return [
            'import_id' => $importId,
            'lead_id' => $leadId,
            'row_number' => $row['row_number'] ?? null,
            'outcome' => $outcome,
            'company_name' => mb_substr((string) ($row['company_name'] ?? ''), 0, 255) ?: null,
            'contact_name' => mb_substr((string) ($row['contact_name'] ?? ''), 0, 255) ?: null,
            'phone' => mb_substr((string) ($row['phone'] ?? ''), 0, 255) ?: null,
            'email' => mb_substr((string) ($row['email'] ?? ''), 0, 255) ?: null,
            'reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function parseCsv(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        $content = preg_replace('/^\xEF\xBB\xBF/', '', (string) $content);

        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $content) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parsed = str_getcsv($line);

            if (! empty($parsed)) {
                $rows[] = $parsed;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyPreview(): array
    {
        return [
            'total_rows' => 0,
            'valid_rows' => 0,
            'duplicate_rows' => 0,
            'invalid_rows' => 0,
            'columns' => [],
            'missing_columns' => self::REQUIRED_COLUMNS,
            'preview' => [],
            'rows' => [],
        ];
    }
}
