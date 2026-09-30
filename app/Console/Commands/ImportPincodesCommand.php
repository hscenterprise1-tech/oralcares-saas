<?php

namespace App\Console\Commands;

use App\Models\GeoArea;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoDistrict;
use App\Models\GeoPincode;
use App\Models\GeoState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportPincodesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geo:import-pincodes 
                            {file? : Path to the India postal CSV file}
                            {--dry-run : Validate and simulate without writing to database}
                            {--chunk=500 : Number of rows per transaction chunk}
                            {--country=IN : Target ISO country code}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import or reconcile nationwide India postal pincode dataset into canonical geo master tables.';

    /**
     * State name alias dictionary for Indian postal variations.
     *
     * @var array<string, string>
     */
    protected array $stateAliases = [
        'andaman nicobar' => 'AN',
        'andaman & nicobar islands' => 'AN',
        'andaman and nicobar islands' => 'AN',
        'andaman and nicobar' => 'AN',
        'andhra pradesh' => 'AP',
        'arunachal pradesh' => 'AR',
        'assam' => 'AS',
        'bihar' => 'BR',
        'chandigarh' => 'CH',
        'chhattisgarh' => 'CG',
        'chhatisgarh' => 'CG',
        'dadra & nagar haveli' => 'DH',
        'daman & diu' => 'DH',
        'dadra and nagar haveli and daman and diu' => 'DH',
        'dadra and nagar haveli' => 'DH',
        'daman and diu' => 'DH',
        'delhi' => 'DL',
        'delhi (nct)' => 'DL',
        'nct of delhi' => 'DL',
        'goa' => 'GA',
        'gujarat' => 'GJ',
        'haryana' => 'HR',
        'himachal pradesh' => 'HP',
        'jammu & kashmir' => 'JK',
        'jammu and kashmir' => 'JK',
        'jharkhand' => 'JH',
        'karnataka' => 'KA',
        'kerala' => 'KL',
        'ladakh' => 'LA',
        'lakshadweep' => 'LD',
        'lakshdweep' => 'LD',
        'madhya pradesh' => 'MP',
        'maharashtra' => 'MH',
        'manipur' => 'MN',
        'meghalaya' => 'ML',
        'mizoram' => 'MZ',
        'nagaland' => 'NL',
        'odisha' => 'OD',
        'orissa' => 'OD',
        'pondicherry' => 'PY',
        'puducherry' => 'PY',
        'punjab' => 'PB',
        'rajasthan' => 'RJ',
        'sikkim' => 'SK',
        'tamil nadu' => 'TN',
        'telangana' => 'TS',
        'tripura' => 'TR',
        'uttar pradesh' => 'UP',
        'uttaranchal' => 'UK',
        'uttarakhand' => 'UK',
        'west bengal' => 'WB',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);
        $filePath = $this->argument('file');
        $isDryRun = (bool) $this->option('dry-run');
        $chunkSize = max(50, (int) $this->option('chunk'));
        $countryCode = strtoupper((string) $this->option('country'));

        $this->info('═══════════════════════════════════════════════════════════');
        $this->info(' OralCares Global Location Intelligence — Pincode Importer');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->line('Mode:       '.($isDryRun ? '<fg=yellow>DRY-RUN (No DB modifications)</>' : '<fg=green>LIVE IMPORT (Database Transactional)</>'));
        $this->line("Country:    {$countryCode}");
        $this->line("Chunk Size: {$chunkSize}");

        if (! $filePath) {
            $defaultPath = storage_path('app/geo/india-pincodes.csv');
            if (file_exists($defaultPath)) {
                $filePath = $defaultPath;
                $this->info("Found default dataset at: {$filePath}");
            } else {
                $this->error("Dataset file not found at {$defaultPath}.");
                $this->line('Please provide an approved source file path.');

                return Command::FAILURE;
            }
        }

        if (! file_exists($filePath) || ! is_readable($filePath)) {
            $this->error("File not found or not readable: {$filePath}");

            return Command::FAILURE;
        }

        // 1. Resolve Country
        $country = GeoCountry::where('code', $countryCode)->first();
        if (! $country && ! $isDryRun) {
            $country = GeoCountry::create([
                'code' => $countryCode,
                'name' => $countryCode === 'IN' ? 'India' : $countryCode,
                'phone_code' => $countryCode === 'IN' ? '+91' : null,
                'currency_code' => $countryCode === 'IN' ? 'INR' : 'USD',
                'is_active' => true,
            ]);
        }

        $countryId = $country?->id ?? 1;

        // In-memory lookup caches for state/district/city/area/pincode
        $stateCache = [];
        $districtCache = [];
        $cityCache = [];
        $areaCache = [];
        $pincodeSet = [];

        foreach (GeoState::where('country_id', $countryId)->get() as $s) {
            $stateCache[strtolower($s->name)] = $s;
            $stateCache[strtolower($s->code)] = $s;
        }

        foreach (GeoDistrict::all() as $d) {
            $districtCache[$d->state_id.':'.strtolower($d->name)] = $d;
        }

        foreach (GeoCity::all() as $c) {
            $cityCache[$c->district_id.':'.strtolower($c->name)] = $c;
        }

        foreach (GeoArea::all() as $a) {
            $areaCache[$a->city_id.':'.strtolower($a->name)] = $a;
        }

        foreach (GeoPincode::all() as $p) {
            $pincodeSet[$p->pincode.':'.$p->city_id.':'.($p->area_id ?? '0')] = true;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $this->error("Failed to open stream for file: {$filePath}");

            return Command::FAILURE;
        }

        // Read header
        $header = fgetcsv($handle);
        if (! $header) {
            $this->error('CSV file is empty or missing headers.');
            fclose($handle);

            return Command::FAILURE;
        }

        $columnMap = $this->mapHeaders($header);
        $this->line("Mapped Headers: Pincode=[{$columnMap['pincode']}], State=[{$columnMap['state']}], District=[{$columnMap['district']}], City=[{$columnMap['city']}], Area=[{$columnMap['area']}]");

        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        // Prepare error logging stream
        $errorLogDir = storage_path('logs');
        if (! File::isDirectory($errorLogDir)) {
            File::makeDirectory($errorLogDir, 0755, true);
        }
        $errorLogPath = storage_path('logs/geo-import-errors.csv');
        $errorHandle = fopen($errorLogPath, 'w');
        fputcsv($errorHandle, ['row_number', 'pincode', 'post_office', 'district', 'city', 'state', 'failure_reason']);

        $chunkBuffer = [];
        $rowNumber = 1;

        $this->output->progressStart();

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $stats['processed']++;

            $parsed = $this->extractRowData($row, $columnMap, $rowNumber);

            if (! $parsed['valid']) {
                $stats['failed']++;
                fputcsv($errorHandle, [
                    $rowNumber,
                    $row[$columnMap['pincode'] ?? 0] ?? '',
                    $row[$columnMap['area'] ?? 0] ?? '',
                    $row[$columnMap['district'] ?? 0] ?? '',
                    $row[$columnMap['city'] ?? 0] ?? '',
                    $row[$columnMap['state'] ?? 0] ?? '',
                    $parsed['error'] ?? 'Validation failure',
                ]);

                continue;
            }

            $chunkBuffer[] = $parsed['data'];

            if (count($chunkBuffer) >= $chunkSize) {
                $this->processChunk($chunkBuffer, $countryId, $stateCache, $districtCache, $cityCache, $areaCache, $pincodeSet, $isDryRun, $stats);
                $chunkBuffer = [];
                $this->output->progressAdvance($chunkSize);
            }
        }

        if (! empty($chunkBuffer)) {
            $this->processChunk($chunkBuffer, $countryId, $stateCache, $districtCache, $cityCache, $areaCache, $pincodeSet, $isDryRun, $stats);
            $this->output->progressAdvance(count($chunkBuffer));
        }

        $this->output->progressFinish();
        fclose($handle);
        fclose($errorHandle);

        // Clear geo cache
        if (! $isDryRun) {
            Cache::flush();
        }

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info(' Import Summary & Reconciliation Audit');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->table(
            ['Metric', 'Count', 'Status'],
            [
                ['Total Source Rows Processed', number_format($stats['processed']), 'Scanned'],
                ['Pincode Records Created', number_format($stats['created']), $isDryRun ? 'Simulated' : 'Written'],
                ['Pincode Records Updated / Linked', number_format($stats['updated']), $isDryRun ? 'Simulated' : 'Written'],
                ['Skipped (Duplicate / No-op)', number_format($stats['skipped']), 'Preserved'],
                ['Failed (Malformed / Rejected)', number_format($stats['failed']), 'Logged to errors CSV'],
                ['Execution Duration', "{$duration} seconds", 'Completed'],
            ]
        );

        $this->line("Error Audit Log saved to: <fg=cyan>{$errorLogPath}</>");

        return Command::SUCCESS;
    }

    /**
     * Map CSV headers to standard geo keys.
     *
     * @param  list<string>  $header
     * @return array<string, int|null>
     */
    protected function mapHeaders(array $header): array
    {
        $normalized = array_map(fn ($h) => strtolower(trim(str_replace(['_', ' ', '-'], '', (string) $h))), $header);

        $findIdx = function (array $candidates) use ($normalized) {
            foreach ($candidates as $c) {
                $idx = array_search(strtolower(str_replace(['_', ' ', '-'], '', $c)), $normalized, true);
                if ($idx !== false) {
                    return $idx;
                }
            }

            return null;
        };

        return [
            'pincode' => $findIdx(['pincode', 'pin', 'postalcode', 'zip', 'zipcode']),
            'area' => $findIdx(['postofficename', 'office', 'officename', 'locality', 'postoffice', 'area', 'sublocality']),
            'district' => $findIdx(['districtsname', 'district', 'districtname', 'dist']),
            'state' => $findIdx(['state', 'statename', 'st']),
            'city' => $findIdx(['city', 'cityname', 'taluk', 'taluka', 'divisionname', 'division']),
            'latitude' => $findIdx(['latitude', 'lat']),
            'longitude' => $findIdx(['longitude', 'lng', 'long']),
        ];
    }

    /**
     * Extract and normalize row values.
     *
     * @param  list<string>  $row
     * @param  array<string, int|null>  $map
     * @return array{valid: bool, data?: array<string, mixed>, error?: string}
     */
    protected function extractRowData(array $row, array $map, int $rowNumber): array
    {
        $getVal = fn ($key) => ($map[$key] !== null && isset($row[$map[$key]])) ? trim((string) $row[$map[$key]]) : null;

        $pincode = preg_replace('/[^0-9]/', '', (string) $getVal('pincode'));
        $state = $getVal('state');
        $district = $getVal('district');
        $city = $getVal('city') ?? $district;
        $area = $getVal('area');
        $lat = $getVal('latitude');
        $lng = $getVal('longitude');

        // Validation rules
        if (strlen($pincode) !== 6 || ! preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            return ['valid' => false, 'error' => "Invalid 6-digit pincode '{$pincode}' at row {$rowNumber}"];
        }

        if (! $state || ! $district) {
            return ['valid' => false, 'error' => "Missing State or District at row {$rowNumber}"];
        }

        return [
            'valid' => true,
            'data' => [
                'pincode' => $pincode,
                'state' => $state,
                'district' => $district,
                'city' => $city ?: $district,
                'area' => $area,
                'latitude' => is_numeric($lat) ? (float) $lat : null,
                'longitude' => is_numeric($lng) ? (float) $lng : null,
            ],
        ];
    }

    /**
     * Process a batch chunk of rows transactionally.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, GeoState>  $stateCache
     * @param  array<string, GeoDistrict>  $districtCache
     * @param  array<string, GeoCity>  $cityCache
     * @param  array<string, GeoArea>  $areaCache
     * @param  array<string, bool>  $pincodeSet
     * @param  array<string, int>  $stats
     */
    protected function processChunk(
        array $rows,
        int $countryId,
        array &$stateCache,
        array &$districtCache,
        array &$cityCache,
        array &$areaCache,
        array &$pincodeSet,
        bool $isDryRun,
        array &$stats
    ): void {
        if ($isDryRun) {
            foreach ($rows as $r) {
                $stats['created']++;
            }

            return;
        }

        DB::transaction(function () use ($rows, $countryId, &$stateCache, &$districtCache, &$cityCache, &$areaCache, &$pincodeSet, &$stats) {
            $pincodesToInsert = [];

            foreach ($rows as $item) {
                // 1. Resolve State (with alias mapping)
                $rawStateKey = strtolower(trim((string) $item['state']));
                $stateCode = $this->stateAliases[$rawStateKey] ?? null;

                $state = null;
                if ($stateCode && isset($stateCache[strtolower($stateCode)])) {
                    $state = $stateCache[strtolower($stateCode)];
                } elseif (isset($stateCache[$rawStateKey])) {
                    $state = $stateCache[$rawStateKey];
                } else {
                    $state = GeoState::where('country_id', $countryId)
                        ->where(function ($q) use ($item, $stateCode) {
                            $q->where('name', $item['state']);
                            if ($stateCode) {
                                $q->orWhere('code', $stateCode);
                            }
                        })
                        ->first();

                    if (! $state) {
                        $state = GeoState::create([
                            'country_id' => $countryId,
                            'name' => $item['state'],
                            'code' => $stateCode ?? strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', (string) $item['state']), 0, 2)),
                            'type' => 'state',
                            'is_active' => true,
                        ]);
                    }

                    $stateCache[$rawStateKey] = $state;
                    $stateCache[strtolower($state->code)] = $state;
                    $stateCache[strtolower($state->name)] = $state;
                }

                // 2. Resolve District
                $districtName = trim((string) $item['district']);
                $districtKey = $state->id.':'.strtolower($districtName);
                if (! isset($districtCache[$districtKey])) {
                    $district = GeoDistrict::firstOrCreate(
                        ['state_id' => $state->id, 'name' => $districtName],
                        ['is_active' => true]
                    );
                    $districtCache[$districtKey] = $district;
                }
                $district = $districtCache[$districtKey];

                // 3. Resolve City
                $cityName = trim((string) $item['city']);
                $cityKey = $district->id.':'.strtolower($cityName);
                if (! isset($cityCache[$cityKey])) {
                    $city = GeoCity::firstOrCreate(
                        ['district_id' => $district->id, 'name' => $cityName],
                        [
                            'state_id' => $state->id,
                            'slug' => Str::slug($cityName),
                            'tier' => 'tier_2',
                            'is_active' => true,
                        ]
                    );
                    $cityCache[$cityKey] = $city;
                }
                $city = $cityCache[$cityKey];

                // 4. Resolve Area (if provided)
                $areaId = null;
                $areaName = ! empty($item['area']) ? trim((string) $item['area']) : null;
                if ($areaName) {
                    $areaKey = $city->id.':'.strtolower($areaName);
                    if (! isset($areaCache[$areaKey])) {
                        $area = GeoArea::firstOrCreate(
                            ['city_id' => $city->id, 'name' => $areaName],
                            [
                                'slug' => Str::slug($areaName),
                                'is_active' => true,
                            ]
                        );
                        $areaCache[$areaKey] = $area;
                    }
                    $areaId = $areaCache[$areaKey]->id;
                }

                // 5. Upsert Pincode
                $compositeKey = $item['pincode'].':'.$city->id.':'.($areaId ?? '0');

                if (isset($pincodeSet[$compositeKey])) {
                    $stats['skipped']++;
                } else {
                    $pincodesToInsert[] = [
                        'pincode' => $item['pincode'],
                        'area_id' => $areaId,
                        'city_id' => $city->id,
                        'district_id' => $district->id,
                        'state_id' => $state->id,
                        'latitude' => $item['latitude'],
                        'longitude' => $item['longitude'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $pincodeSet[$compositeKey] = true;
                    $stats['created']++;
                }
            }

            if (! empty($pincodesToInsert)) {
                GeoPincode::insert($pincodesToInsert);
            }
        });
    }
}
