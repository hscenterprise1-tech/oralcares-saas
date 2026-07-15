<?php

namespace App\Models;

use App\LeadStatus;
use App\UserType;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'slug',
        'contact_name',
        'email',
        'phone',
        'status',
        'source',
        'assigned_user_id',
        'assigned_team_id',
        'clinic_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Lead $lead) {
            if (empty($lead->slug)) {
                $lead->slug = Str::slug($lead->company_name);
            }

            $originalSlug = $lead->slug;
            $count = 0;
            while (static::where('slug', $lead->slug)->exists()) {
                $suffix = strtolower(Str::random(6));
                $lead->slug = $originalSlug.'-'.$suffix;
                $count++;
                if ($count > 10) {
                    break;
                }
            }
        });

        static::saving(function (Lead $lead) {
            // 1. Enforce exclusive assignment semantics (cannot assign to both user and team)
            if ($lead->assigned_user_id !== null && $lead->assigned_team_id !== null) {
                throw new InvalidArgumentException('A lead cannot be assigned to both a user and a team simultaneously.');
            }

            // 2. Enforce platform-only context assignment validation (no clinic_user allowed)
            if ($lead->assigned_user_id !== null) {
                $user = User::find($lead->assigned_user_id);
                if ($user && $user->user_type === UserType::ClinicUser) {
                    throw new InvalidArgumentException('CRM records cannot be assigned to clinic users.');
                }
            }
        });
    }

    /**
     * Get the internal platform user assigned to this lead.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Get the platform team assigned to this lead.
     */
    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }

    /**
     * Get the resulting clinic won from this lead.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    /**
     * Get the append-only activity timeline events logged for this lead.
     *
     * @return HasMany<LeadActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the tasks related to this lead.
     *
     * @return MorphMany<Task, $this>
     */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /**
     * Get the centralized, canonical lead sources.
     *
     * @return array<string, string>
     */
    public static function sources(): array
    {
        return [
            'website' => 'Website',
            'instagram' => 'Instagram',
            'whatsapp' => 'WhatsApp',
            'referral' => 'Referral',
            'cold_call' => 'Cold Call',
            'exhibition' => 'Exhibition',
            'partner' => 'Partner',
            'manual_entry' => 'Manual Entry',
        ];
    }
}
