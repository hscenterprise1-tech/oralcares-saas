<?php

namespace App\Shared\Notification\Jobs;

use App\Models\User;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Services\NotificationEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BatchNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  Collection<int, User>  $users
     */
    public function __construct(
        public Collection $users,
        public SendNotificationDTO $dto
    ) {}

    public function handle(NotificationEngineService $engine): void
    {
        $engine->send($this->users, $this->dto);
    }
}
