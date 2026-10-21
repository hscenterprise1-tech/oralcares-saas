<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tooth of a periodontal exam. Site order in every array is
 * MB, B, DB, ML, L, DL (buccal row then lingual row, mesial to distal for
 * the tooth's own side of the arch).
 */
class PeriodontalTooth extends Model
{
    use ClinicScoped;

    public const SITES = ['MB', 'B', 'DB', 'ML', 'L', 'DL'];

    protected $table = 'periodontal_teeth';

    protected $fillable = [
        'periodontal_exam_id',
        'clinic_id',
        'tooth_number',
        'missing',
        'probing_depths',
        'gingival_margins',
        'bleeding',
        'plaque',
        'mobility',
        'furcation',
    ];

    protected function casts(): array
    {
        return [
            'tooth_number' => 'integer',
            'missing' => 'boolean',
            'probing_depths' => 'array',
            'gingival_margins' => 'array',
            'bleeding' => 'array',
            'plaque' => 'array',
            'mobility' => 'integer',
            'furcation' => 'integer',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(PeriodontalExam::class, 'periodontal_exam_id');
    }
}
