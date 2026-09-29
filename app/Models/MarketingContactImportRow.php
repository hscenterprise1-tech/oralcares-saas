<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an uploaded file, and what the importer decided about it.
 *
 * This is what makes a batch answerable. A contact who arrives in a second
 * file is not created again — but the file did contain them, and that fact
 * lives here rather than being thrown away.
 */
class MarketingContactImportRow extends Model
{
    use HasFactory;

    /** The file added this person to the database. */
    public const OUTCOME_CREATED = 'created';

    /** They were already on the list; this file contained them anyway. */
    public const OUTCOME_EXISTING = 'existing';

    /** The row could not be read, so no contact came of it. */
    public const OUTCOME_INVALID = 'invalid';

    /**
     * The outcomes that point at a real contact.
     *
     * `created` and `existing` together are the batch's membership — the unique
     * people the file actually contained, which is what its marketing progress
     * is measured over.
     *
     * @var list<string>
     */
    public const CONTACT_OUTCOMES = [self::OUTCOME_CREATED, self::OUTCOME_EXISTING];

    protected $fillable = [
        'import_id',
        'contact_id',
        'row_number',
        'outcome',
        'name',
        'phone',
        'normalized_phone',
        'reason',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(MarketingContactImport::class, 'import_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(MarketingContact::class, 'contact_id');
    }
}
