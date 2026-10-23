<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{

    protected $fillable = [
        'user_id',
        'image',
        'date',
        'time',
        'latitude',
        'longitude',
        'clock_out_time',
        'clock_out_image',
        'clock_out_lat',
        'clock_out_lon',
        'stay_duration',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
