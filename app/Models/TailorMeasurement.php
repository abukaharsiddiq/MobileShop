<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TailorMeasurement extends Model
{
    protected $fillable = [
    'user_id',
    'profile_name',
    'length',
    'body_chest',
    'waist',
    'hip',
    'shoulder',
    'sleeve_length',
    'neck',
    'armhole',
    'pant_length',
    'pant_waist',
    'thigh',
    'ankle',
    'knee',
    'crotch',
    'special_note',
];

}
