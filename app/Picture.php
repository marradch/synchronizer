<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Picture extends Model
{
    protected $fillable = [
        'offer_id',
        'url',
        'status',
        'status_date',
        'synchronized',
        'synchronize_date',
        'vk_id',
    ];

    protected $table = 'pictures';
}
