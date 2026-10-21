<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Something added to a signed encounter: an addendum (new information) or an
 * amendment (a correction to one section, keeping the text it supersedes and
 * the reason). Signed by its author when written; never edited.
 */
class ClinicalEncounterAddendum extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    public const ADDENDUM = 'addendum';

    public const AMENDMENT = 'amendment';

    protected $table = 'clinical_encounter_addenda';

    protected $fillable = [
        'clinic_id',
        'encounter_id',
        'kind',
        'section',
        'content',
        'previous_content',
        'reason',
        'author_id',
        'signed_at',
        'content_hash',
    ];

    protected static function booted(): void
    {
        foreach (['updating', 'deleting'] as $event) {
            static::$event(fn () => throw new LogicException('A signed addendum is never changed; add another instead.'));
        }
    }

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'author_id');
    }
}
