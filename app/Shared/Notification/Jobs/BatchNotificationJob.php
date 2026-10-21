<?php

namespace App\Shared\Notification\Jobs;

use App\Models\User;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Services\NotificationEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BatchNotificationJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  Collection<int, User>  $users
     */
    public function __construct(
        public Collection $users,
        public SendNotificationDTO $dto
    ) {
        // The message body may name patients: encrypted at rest in the queue.
        $this->onQueue(config('queue.queues.notifications'));
    }

    public function handle(NotificationEngineService $engine): void
    {
        $engine->send($this->users, $this->dto);
    }
}
