<?php

namespace App\Events;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerProvisioned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Organization $organization;

    public User $adminUser;

    /**
     * Create a new event instance.
     */
    public function __construct(Organization $organization, User $adminUser)
    {
        $this->organization = $organization;
        $this->adminUser = $adminUser;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel-name'),
        ];
    }
}
