<?php

namespace App\Services\Marketing;

use App\Models\MarketingContact;
use App\Models\MarketingContactImportRow;
use App\Models\MarketingTag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContactImportService
{
    /**
     * Generate official CSV template content.
     */
    public function getCsvTemplate(): string
    {
        $headers = ['name', 'phone', 'organization', 'state', 'city', 'area', 'tags'];
        $rows = [
            ['Rahul Patel', '919876543210', 'Patel Dental Clinic', 'Gujarat', 'Vadodara', 'Alkapuri', 'Dentist|Prospect'],
            ['Amit Shah', '919825012345', 'Shah Dental Hospital', 'Gujarat', 'Ahmedabad', 'Navrangpura', 'Dental Clinic'],
            ['Priya Mehta', '919812345678', 'Mehta Hospital', 'Gujarat', 'Surat', 'Athwalines', 'Hospital|Existing Customer'],
            ['Vikram Desai', '919898765432', 'Desai Medical Supplies', 'Rajasthan', 'Jaipur', 'Vaishali Nagar', 'Supplier|Distributor'],
        ];

        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return $content;
    }

    /**
     * Parse uploaded file into raw data rows.
     */
    public function parseFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();

        if ($extension === 'csv' || $extension === 'txt') {
            return $this->parseCsv($path);
        }

        if ($extension === 'xlsx') {
            return $this->parseXlsx($path);
        }

        return $this->parseCsv($path);
    }

    /**
     * Preview import file with validation, duplicate detection, and column mapping.
     */
    public function previewImport(UploadedFile $file, array $customMapping = [], ?int $orgId = null): array
    {
        $rawRows = $this->parseFile($file);

        if (empty($rawRows)) {
            return [
                'total_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'duplicate_rows' => 0,
                'new_contacts' => 0,
                'columns' => [],
                'column_mapping' => [],
                'preview' => [],
                'rows' => [],
            ];
        }

        // Header row
        $headers = array_shift($rawRows);
        $cleanHeaders = array_map(fn ($h) => strtolower(trim((string) $h)), $headers);

        // Auto-detect or use custom column mapping
        $mapping = $this->resolveColumnMapping($cleanHeaders, $customMapping);

        // Fetch existing contacts for DB duplicate checks (phone is the authoritative match;
        // organization name is a secondary signal surfaced for human review, not auto-blocked).
        $existingContacts = MarketingContact::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->select(['id', 'name', 'phone', 'normalized_phone', 'organization', 'created_at'])
            ->get();

        $existingByPhone = $existingContacts->keyBy('normalized_phone');
        $existingByOrg = $existingContacts
            ->filter(fn ($c) => ! empty($c->organization))
            ->groupBy(fn ($c) => Str::lower(trim((string) $c->organization)));

        $processedRows = [];
        $seenFilePhones = [];

        $totalRows = count($rawRows);
        $validRowsCount = 0;
        $invalidRowsCount = 0;
        $duplicateRowsCount = 0;
        $newContactsCount = 0;

        foreach ($rawRows as $index => $row) {
            $nameVal = trim((string) ($row[$mapping['name']] ?? ''));
            $phoneVal = trim((string) ($row[$mapping['phone']] ?? ''));
            $orgVal = isset($mapping['organization']) && isset($row[$mapping['organization']]) ? trim((string) $row[$mapping['organization']]) : '';
            $cityVal = isset($mapping['city']) && isset($row[$mapping['city']]) ? trim((string) $row[$mapping['city']]) : '';
            $stateVal = isset($mapping['state']) && isset($row[$mapping['state']]) ? trim((string) $row[$mapping['state']]) : '';
            $areaVal = isset($mapping['area']) && isset($row[$mapping['area']]) ? trim((string) $row[$mapping['area']]) : '';
            $tagsVal = isset($mapping['tags']) && isset($row[$mapping['tags']]) ? trim((string) $row[$mapping['tags']]) : '';

            $normalizedPhone = PhoneNormalizer::normalize($phoneVal);
            $isValidPhone = PhoneNormalizer::isValid($phoneVal);
            $isValidName = ! empty($nameVal);

            $status = 'VALID';
            $errorMsg = null;
            $existingContactInfo = null;

            $orgMatch = ! empty($orgVal) ? $existingByOrg->get(Str::lower(trim($orgVal))) : null;

            if (! $isValidName) {
                $status = 'INVALID';
                $errorMsg = 'Name is required.';
            } elseif (! $isValidPhone || empty($normalizedPhone)) {
                $status = 'INVALID';
                $errorMsg = 'Invalid phone number format.';
            } elseif (isset($seenFilePhones[$normalizedPhone])) {
                $status = 'FILE_DUPLICATE';
                $errorMsg = 'Duplicate phone number inside uploaded file.';
            } elseif ($existingByPhone->has($normalizedPhone)) {
                $status = 'EXISTS_IN_DB';
                $errorMsg = 'A contact with this mobile number already exists.';
                $existingContactInfo = $this->formatExistingContact($existingByPhone->get($normalizedPhone));
            } elseif ($orgMatch) {
                // Same organization name, different phone number — likely another
                // person at the same clinic/hospital. Flagged for review, not blocked.
                $status = 'POSSIBLE_DUPLICATE_ORG';
                $errorMsg = 'Another contact from this organization already exists — please confirm this is a different person.';
                $existingContactInfo = $this->formatExistingContact($orgMatch->first());
            }

            if ($status === 'INVALID') {
                $invalidRowsCount++;
            } elseif ($status === 'FILE_DUPLICATE' || $status === 'EXISTS_IN_DB') {
                $duplicateRowsCount++;
                $seenFilePhones[$normalizedPhone] = true;
            } else {
                // VALID and POSSIBLE_DUPLICATE_ORG rows are both importable —
                // the org-match is a heads-up, not a block.
                $validRowsCount++;
                $newContactsCount++;
                $seenFilePhones[$normalizedPhone] = true;
            }

            $processedRows[] = [
                'row_number' => $index + 2, // 1-based + header
                'name' => $nameVal,
                'phone' => $phoneVal,
                'normalized_phone' => $normalizedPhone,
                'organization' => $orgVal,
                'city' => $cityVal,
                'state' => $stateVal,
                'area' => $areaVal,
                'tags' => $tagsVal,
                'status' => $status,
                'error_message' => $errorMsg,
                'existing_contact' => $existingContactInfo,
            ];
        }

        return [
            'total_rows' => $totalRows,
            'valid_rows' => $validRowsCount,
            'invalid_rows' => $invalidRowsCount,
            'duplicate_rows' => $duplicateRowsCount,
            'new_contacts' => $newContactsCount,
            'columns' => $cleanHeaders,
            'column_mapping' => $mapping,
            'preview' => array_slice($processedRows, 0, 20),
            'rows' => $processedRows,
        ];
    }

    /**
     * Process bulk import execution inside transaction.
     *
     * @param  array  $rows  Validated row data from the preview step
     * @param  ?int  $orgId  Tenant organisation ID
     * @param  ?int  $userId  Acting user ID
     * @param  ?int  $importId  The MarketingContactImport record ID to stamp on created contacts
     */
    public function processImport(array $rows, ?int $orgId = null, ?int $userId = null, ?int $importId = null): array
    {
        $importedCount = 0;
        $skippedCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;

        /*
         * Membership rows are collected and inserted in one go rather than one
         * at a time: a ten-thousand-row file would otherwise mean ten thousand
         * round trips inside the transaction.
         */
        $memberships = [];
        $rowNumber = 0;

        /*
         * `$importId` has to be captured here. It is what stamps every created
         * contact with the batch it came from, and without it in the `use`
         * clause the closure raised an undefined-variable error on the first
         * row — so a small import returned 500 and a queued one silently wrote
         * contacts with no batch at all. Batch traceability is the whole point
         * of this argument.
         */
        DB::transaction(function () use ($rows, $orgId, $userId, $importId, &$importedCount, &$skippedCount, &$duplicateCount, &$invalidCount, &$memberships, &$rowNumber) {
            $existingDbPhones = MarketingContact::query()
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->pluck('id', 'normalized_phone')
                ->toArray();

            $processedFilePhones = [];

            foreach ($rows as $row) {
                $status = $row['status'] ?? 'VALID';

                if ($status === 'INVALID') {
                    $invalidCount++;
                    $rowNumber++;
                    $memberships[] = self::membershipRow($importId, null, $rowNumber, MarketingContactImportRow::OUTCOME_INVALID, $row, 'Marked invalid during preview');

                    continue;
                }

                $normalizedPhone = PhoneNormalizer::normalize($row['phone'] ?? '');

                if (empty($normalizedPhone) || empty($row['name'])) {
                    $invalidCount++;
                    $rowNumber++;
                    $memberships[] = self::membershipRow($importId, null, $rowNumber, MarketingContactImportRow::OUTCOME_INVALID, $row, 'Missing a name or a usable phone number');

                    continue;
                }

                if (isset($existingDbPhones[$normalizedPhone]) || isset($processedFilePhones[$normalizedPhone])) {
                    $duplicateCount++;
                    $skippedCount++;
                    $rowNumber++;

                    /*
                     * The contact is not created again — but this file did
                     * contain them, and that is the fact the batch page needs.
                     * A repeat *within the same file* has no membership row of
                     * its own: the unique index would reject it, and counting
                     * one person twice would inflate the batch.
                     */
                    /*
                     * Only a contact that existed *before* this run gets a
                     * membership row here. A phone repeated later in the same
                     * file already has one from the row that created it, and a
                     * second would violate the one-row-per-contact-per-import
                     * rule the batch's counts depend on.
                     */
                    if (isset($existingDbPhones[$normalizedPhone]) && ! isset($processedFilePhones[$normalizedPhone])) {
                        $memberships[] = self::membershipRow(
                            $importId,
                            $existingDbPhones[$normalizedPhone],
                            $rowNumber,
                            MarketingContactImportRow::OUTCOME_EXISTING,
                            $row,
                            null
                        );
                    }

                    continue;
                }

                // Create contact — stamp the import batch so it is traceable
                $contact = MarketingContact::create([
                    'organization_id' => $orgId,
                    'import_id' => $importId,
                    'name' => trim($row['name']),
                    'phone' => trim($row['phone']),
                    'normalized_phone' => $normalizedPhone,
                    'organization' => ! empty($row['organization']) ? trim($row['organization']) : null,
                    'city' => ! empty($row['city']) ? trim($row['city']) : null,
                    'state' => ! empty($row['state']) ? trim($row['state']) : null,
                    'area' => ! empty($row['area']) ? trim($row['area']) : null,
                    'marketing_status' => 'active',
                    'created_by_user_id' => $userId,
                ]);

                // Attach tags
                if (! empty($row['tags'])) {
                    $tagNames = preg_split('/[|,;]+/', (string) $row['tags']);
                    $tagIds = [];

                    foreach ($tagNames as $tagName) {
                        $cleanTag = trim($tagName);
                        if (empty($cleanTag)) {
                            continue;
                        }

                        $slug = Str::slug($cleanTag);
                        $tag = MarketingTag::firstOrCreate(
                            [
                                'organization_id' => $orgId,
                                'slug' => $slug,
                            ],
                            [
                                'name' => $cleanTag,
                                'color' => 'neutral',
                            ]
                        );

                        $tagIds[] = $tag->id;
                    }

                    if (! empty($tagIds)) {
                        $contact->tags()->sync(array_unique($tagIds));
                    }
                }

                $processedFilePhones[$normalizedPhone] = true;
                $importedCount++;
                $rowNumber++;
                $memberships[] = self::membershipRow($importId, $contact->id, $rowNumber, MarketingContactImportRow::OUTCOME_CREATED, $row, null);
            }

            if ($importId !== null && $memberships !== []) {
                foreach (array_chunk($memberships, 500) as $chunk) {
                    MarketingContactImportRow::insert($chunk);
                }
            }
        });

        return [
            'imported_count' => $importedCount,
            'skipped_count' => $skippedCount,
            'duplicate_count' => $duplicateCount,
            'invalid_count' => $invalidCount,
        ];
    }

    /**
     * Shape an existing contact record for duplicate-review display in the import preview.
     *
     * @return array{id: int, name: string, phone: string, organization: ?string, added_on: string}
     */
    protected function formatExistingContact(MarketingContact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'phone' => $contact->phone,
            'organization' => $contact->organization,
            'added_on' => $contact->created_at?->toDateString(),
        ];
    }

    /**
     * Resolve CSV/XLSX header column mapping.
     */
    protected function resolveColumnMapping(array $headers, array $customMapping): array
    {
        $mapping = [];
        $headerMap = array_flip($headers);

        $fields = ['name', 'phone', 'organization', 'state', 'city', 'area', 'tags'];
        $aliases = [
            'name' => ['name', 'contact_name', 'full_name', 'first_name', 'client_name', 'doctor_name'],
            'phone' => ['phone', 'mobile', 'phone_number', 'contact_number', 'mobile_number', 'tel', 'whatsapp'],
            'organization' => ['organization', 'company', 'clinic', 'hospital', 'business', 'org', 'firm'],
            'state' => ['state', 'province', 'region'],
            'city' => ['city', 'location', 'town', 'district'],
            'area' => ['area', 'locality', 'zone', 'neighbourhood', 'neighborhood', 'sector'],
            'tags' => ['tags', 'tag', 'category', 'categories', 'segment', 'type'],
        ];

        foreach ($fields as $field) {
            if (isset($customMapping[$field]) && is_numeric($customMapping[$field])) {
                $mapping[$field] = (int) $customMapping[$field];

                continue;
            }

            $foundIndex = null;
            foreach ($aliases[$field] as $alias) {
                if (isset($headerMap[$alias])) {
                    $foundIndex = $headerMap[$alias];
                    break;
                }
            }

            $mapping[$field] = $foundIndex ?? ($headerMap[$field] ?? 0);
        }

        return $mapping;
    }

    /**
     * Parse CSV file with BOM stripping and delimiter detection.
     */
    protected function parseCsv(string $filePath): array
    {
        $content = file_get_contents($filePath);
        // Strip UTF-8 BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $rows = [];

        // Auto-detect delimiter
        $sampleLine = $lines[0] ?? '';
        $delimiter = ',';
        if (substr_count($sampleLine, ';') > substr_count($sampleLine, ',')) {
            $delimiter = ';';
        } elseif (substr_count($sampleLine, "\t") > substr_count($sampleLine, ',')) {
            $delimiter = "\t";
        }

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parsed = str_getcsv($line, $delimiter);
            if (! empty($parsed)) {
                $rows[] = $parsed;
            }
        }

        return $rows;
    }

    /**
     * Parse basic XLSX worksheet XML if zip extension available, else fallback to CSV parser.
     */
    protected function parseXlsx(string $filePath): array
    {
        if (! class_exists('ZipArchive')) {
            return $this->parseCsv($filePath);
        }

        $zip = new \ZipArchive;
        if ($zip->open($filePath) !== true) {
            return $this->parseCsv($filePath);
        }

        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml) {
            $xml = simplexml_load_string($sharedStringsXml);
            if ($xml) {
                foreach ($xml->si as $val) {
                    $sharedStrings[] = (string) $val->t;
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (! $sheetXml) {
            return [];
        }

        $xml = simplexml_load_string($sheetXml);
        if (! $xml) {
            return [];
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                $type = (string) $cell['t'];
                $value = (string) $cell->v;

                if ($type === 's' && isset($sharedStrings[(int) $value])) {
                    $value = $sharedStrings[(int) $value];
                }

                $rowData[] = $value;
            }
            if (! empty($rowData)) {
                $rows[] = $rowData;
            }
        }

        return $rows;
    }

    /**
     * One membership row, shaped for a bulk insert.
     *
     * A bulk insert bypasses the model, so the timestamps are set by hand.
     */
    protected static function membershipRow(
        ?int $importId,
        ?int $contactId,
        int $rowNumber,
        string $outcome,
        array $row,
        ?string $reason
    ): array {
        $now = now();

        return [
            'import_id' => $importId,
            'contact_id' => $contactId,
            'row_number' => $rowNumber,
            'outcome' => $outcome,
            'name' => isset($row['name']) ? mb_substr(trim((string) $row['name']), 0, 255) : null,
            'phone' => isset($row['phone']) ? mb_substr(trim((string) $row['phone']), 0, 255) : null,
            'normalized_phone' => PhoneNormalizer::normalize($row['phone'] ?? '') ?: null,
            'reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
