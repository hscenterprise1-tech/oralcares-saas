<?php

namespace App\Models;

use Database\Factories\CompanyEmployeeInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyEmployeeInvitation extends Model
{
    /** @use HasFactory<CompanyEmployeeInvitationFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'token',
        'company_employee_id',
        'email',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Get the employee profile linked to this invitation.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'company_employee_id');
    }

    /**
     * Get the user who created this invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Determine if the invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Determine if the invitation is valid for acceptance.
     */
    public function isValid(): bool
    {
        return $this->accepted_at === null &&
            $this->revoked_at === null &&
            ! $this->isExpired();
    }
}
