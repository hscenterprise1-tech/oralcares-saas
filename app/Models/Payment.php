<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The legacy commercial document this settled, if any.
     *
     * Historical only. Money attaches to a quotation now; this survives so the
     * rows raised under the old agreement flow stay readable.
     */
    public function document()
    {
        return $this->belongsTo(CommercialDocument::class, 'commercial_document_id');
    }

    /** The quotation this payment settles. */
    public function quotation()
    {
        return $this->belongsTo(CompanyQuotation::class, 'company_quotation_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The Finance ledger entry mirroring this payment. Written by
     * CommercialLedgerService once the payment settles.
     */
    public function ledgerEntry()
    {
        return $this->hasOne(CommercialPayment::class, 'payment_id');
    }
}
