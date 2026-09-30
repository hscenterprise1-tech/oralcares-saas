<?php

namespace App\Models;

use App\DemoConfirmationStatus;
use App\DemoOutcome;
use App\DemoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Demo extends Model
{
    use HasFactory;

    public const PLATFORM_GOOGLE_MEET = 'google_meet';

    public const PLATFORM_ZOOM = 'zoom';

    public const PLATFORM_IN_PERSON = 'in_person';

    /**
     * The platforms a demo may be held on, as the form offers them.
     *
     * Kept here rather than in a request rule so the validator, the payload the
     * page renders its selector from, and the model all read one list.
     *
     * @var array<string, string>
     */
    public const PLATFORMS = [
        self::PLATFORM_GOOGLE_MEET => 'Google Meet',
        self::PLATFORM_ZOOM => 'Zoom',
        self::PLATFORM_IN_PERSON => 'In person',
    ];

    protected $fillable = [
        'lead_id',
        'presenter_id',
        'scheduled_at',
        'duration_minutes',
        'demo_mode',
        'meeting_platform',
        'location',
        'status',
        'confirmation_status',
        'meeting_link',
        'meeting_id',
        'passcode',
        'rep_notified_at',
        'customer_reminder_24h_at',
        'customer_reminder_2h_at',
        'notes',
        'feedback',
        'outcome',
        'next_action',
        'objections',
        'rescheduled_from_id',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DemoStatus::class,
            'confirmation_status' => DemoConfirmationStatus::class,
            'outcome' => DemoOutcome::class,
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'rep_notified_at' => 'datetime',
            'customer_reminder_24h_at' => 'datetime',
            'customer_reminder_2h_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Whether this demo is run over a video call rather than at the practice.
     *
     * Reads the platform, not `demo_mode`: the platform is the finer-grained
     * field, and a demo cannot be on Meet and in person at once.
     */
    public function isRemote(): bool
    {
        return $this->meeting_platform !== self::PLATFORM_IN_PERSON;
    }

    /**
     * Where to send someone — a joining URL for a remote demo, the practice
     * address for one held on site. Null when neither has been filled in, which
     * is a demo nobody can attend and which the UI flags.
     */
    public function joiningInstruction(): ?string
    {
        return $this->isRemote()
            ? ($this->meeting_link ?: null)
            : ($this->location ?: null);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function presenter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presenter_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function rescheduledDemos(): HasMany
    {
        return $this->hasMany(self::class, 'rescheduled_from_id');
    }
}
