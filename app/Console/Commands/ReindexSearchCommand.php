<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Models\CommercialDocument;
use App\Models\CommercialInvoice;
use App\Models\CompanyEmployee;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Shared\Search\Services\SearchIndexerService;
use Illuminate\Console\Command;

class ReindexSearchCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'search:index {--fresh : Truncate search_documents before indexing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Idempotently index enterprise models into the global search documents table.';

    /**
     * Execute the console command.
     */
    public function handle(SearchIndexerService $indexer): int
    {
        $this->info('Starting enterprise search indexing...');

        if ($this->option('fresh')) {
            \DB::table('ent_search_documents')->truncate();
            $this->warn('Truncated ent_search_documents table.');
        }

        $models = [
            Lead::class,
            Organization::class,
            Clinic::class,
            CommercialInvoice::class,
            Task::class,
            CommercialDocument::class,
            CompanyEmployee::class,
            User::class,
        ];

        $totalIndexed = 0;

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                continue;
            }

            $count = 0;
            $this->output->write('Indexing '.class_basename($modelClass).'... ');

            try {
                $modelClass::query()->chunk(100, function ($chunk) use ($indexer, &$count, &$totalIndexed) {
                    foreach ($chunk as $record) {
                        $indexer->indexModel($record);
                        $count++;
                        $totalIndexed++;
                    }
                });
                $this->info("Done ({$count} records)");
            } catch (\Throwable $e) {
                $this->error("Failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Indexing completed! Total records indexed: {$totalIndexed}");

        return Command::SUCCESS;
    }
}
