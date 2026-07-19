<?php

namespace App\Models;

use App\TeamStatus;
use Database\Factories\CompanyTeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyTeam extends Model
{
    /** @use HasFactory<CompanyTeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'name',
        'code',
        'description',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TeamStatus::class,
        ];
    }

    /**
     * Get the department that owns the team.
     *
     * @return BelongsTo<CompanyDepartment, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(CompanyDepartment::class, 'department_id');
    }

    /**
     * Get the employees belonging to the team.
     *
     * @return HasMany<CompanyEmployee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(CompanyEmployee::class, 'team_id');
    }
}
