<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ekta device er ekta IMEI number (slot 1 ba 2). ProductImei nijei eta milay (syncNumbers);
 * hate lekha uchit na. active_imei generated column (is_active hole number) unique.
 */
class ProductImeiNumber extends Model
{
    protected $fillable = ['product_imei_id', 'slot', 'imei_no', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function device()
    {
        return $this->belongsTo(ProductImei::class, 'product_imei_id');
    }
}
