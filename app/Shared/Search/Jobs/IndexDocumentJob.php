<?php

namespace App\Shared\Search\Jobs;

use App\Services\ClinicContext;
use App\Shared\Search\Contracts\SearchProviderInterface;
use App\Shared\Search\Services\IndexManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A record deleted before its index job ran has nothing to index; the
     * delete already removed its document. These used to fail and pile up in
     * `failed_jobs`.
     */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Model $model,
        public string $providerClass
    ) {
        // Serialized without loaded relations: restoring them runs scoped
        // queries before `handle()` opens its cross-tenant window, which a
        // worker refuses (TenantContextMissingException). The index reads
        // what it needs itself.
        $this->model = $model->withoutRelations();
        $this->onQueue(config('queue.queues.search'));
    }

    /**
     * Execute the job.
     *
     * The index row carries the record's own `clinic_id`, so building it is
     * deliberately cross-tenant work (one job serves every clinic) and says so.
     */
    public function handle(IndexManagerService $indexManager, ClinicContext $context): void
    {
        /** @var SearchProviderInterface $provider */
        $provider = app($this->providerClass);

        $context->withoutTenancy(fn () => $indexManager->indexModel($this->model, $provider));
    }
}
