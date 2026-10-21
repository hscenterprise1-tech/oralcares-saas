<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a package's entitlement ledger. Takes its clinic from the
 * package. `quantity` is the effect on the remaining balance: a consumption
 * is -1, its reversal +1, an adjustment any signed number. The balance is
 * the package's sessions plus the sum of its entries; `sessions_used` on the
 * package is only a cache of it. Written only by `SessionPackages`.
 */
class SessionPackageUsage extends Model
{
    use ClinicScoped;

    public const CONSUME = 'consume';

    public const REVERSAL = 'reversal';

    public const ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'clinic_id',
        'package_id',
        'physio_session_id',
        'entry_type',
        'quantity',
        'reason',
        'entry_key',
        'used_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    protected function performInsert(Builder $query)
    {
        $packageClinic = PatientSessionPackage::query()->withoutGlobalScopes()->whereKey($this->getAttribute('package_id'))->value('clinic_id');
        if ($packageClinic !== null) {
            $this->setAttribute('clinic_id', $packageClinic);
        }

        return parent::performInsert($query);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(PatientSessionPackage::class, 'package_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PhysioSession::class, 'physio_session_id');
    }
}
