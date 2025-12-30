<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Squareetlabs\VeriFactu\Contracts\VeriFactuRecipient;

class Recipient extends Model implements VeriFactuRecipient
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory()
    {
        return \Database\Factories\Squareetlabs\VeriFactu\Models\RecipientFactory::new();
    }

    protected $table = 'recipients';

    protected $fillable = [
        'invoice_id',
        'name',
        'tax_id',
        'country',
        'id_type', // New field from PR #8 for foreign recipients
        // Otros campos relevantes
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    // VeriFactuRecipient Contract Implementation

    public function getName(): string
    {
        return $this->name;
    }

    public function getTaxId(): ?string
    {
        return $this->tax_id;
    }
}