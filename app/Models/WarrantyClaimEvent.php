<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Claim er service itihash: protita status bodol, ke, kokhon, ki note
class WarrantyClaimEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['warranty_claim_id', 'status', 'note', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
