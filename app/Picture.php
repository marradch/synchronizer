<?php

namespace App;

use Illuminate\Support\Facades\Log;

/**
 * Class Picture
 *
 * @property string $local_path
 * @package App
 */
class Picture extends SynchronizedModel
{
    protected $fillable = [
        'offer_id',
        'url',
        'delete_sign',
        'status',
        'status_date',
        'synchronized',
        'synchronize_date',
        'vk_id',
        'vk_loading_error',
    ];

    protected $table = 'pictures';

    public function offer()
    {
        return $this->belongsTo('App\Offer', 'offer_id', 'id');
    }

    public function getLocalPathAttribute()
    {
        $path = public_path() . '/downloads/' . basename($this->url);
        if (!file_exists($path)) {
            Log::warning("Picture at $path not found, using default one");
            $path = $this->getDefaultAttribute();
        }

        return $path;
    }

    public function getDefaultAttribute()
    {
        return public_path() . env('SHOP_DEFAULT_PICTURE_PATH', null);
    }
}
