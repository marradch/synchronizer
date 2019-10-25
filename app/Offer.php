<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    protected $fillable = [
        'shop_id',
        'shop_category_id',
        'name',
        'price',
        'vendor_code',
        'description',
        'check_sum',
        'status',
        'status_date',
        'synchronized',
        'synchronize_date',
        'vk_id',
    ];

    protected $table = 'offers';

    public function category()
    {
        return $this->belongsTo('App\Category', 'shop_category_id', 'shop_id');
    }

    public function pictures()
    {
        return $this->hasMany('App\Picture', 'offer_id', 'id');
    }

}
