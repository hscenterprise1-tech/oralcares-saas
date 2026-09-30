<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class License extends Model
{
    protected $fillable = [
        'organization_id',
        'plan_name',
        'max_branches',
        'max_users',
        'storage_limit_mb',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function enabledModules(): HasMany
    {
        return $this->hasMany(EnabledModule::class);
    }

    public function hasModule(string $moduleName): bool
    {
        return $this->enabledModules()
            ->where('module_name', $moduleName)
            ->where('is_enabled', true)
            ->exists();
    }

    public function isModuleLicensed(string $moduleName): bool
    {
        return $this->enabledModules()
            ->where('module_name', $moduleName)
            ->exists();
    }

    public function canAddBranch(int $currentBranchCount): bool
    {
        return $currentBranchCount < $this->max_branches;
    }

    public function canAddUser(int $currentUserCount): bool
    {
        return $currentUserCount < $this->max_users;
    }
}
