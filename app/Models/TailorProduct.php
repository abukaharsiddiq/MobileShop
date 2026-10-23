<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TailorProduct extends Model
{

    protected $fillable = [
        'tailor_category_id',
        'dress_name',
        'description',
        'original_image',
        'garment_image_transparent',
        'price',
        'status',
    ];


    public function tailor_category()
    {
        return $this->belongsTo(TailorCategory::class);
    }

}
