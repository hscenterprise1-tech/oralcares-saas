<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommercialDocumentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'commercial_document_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'total',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function document()
    {
        return $this->belongsTo(CommercialDocument::class, 'commercial_document_id');
    }
}
