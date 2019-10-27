<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Class Picture
 *
 * @property string $local_path
 * @package App
 */
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
        return public_path() . '/data/default.png';
    }
}
