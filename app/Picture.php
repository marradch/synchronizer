<?php

namespace App;

use App\Traits\Loggable;
use Illuminate\Support\Facades\Log;

/**
 * Class Picture
 *
 * @property string $local_path
 * @package App
 */
class Picture extends SynchronizedModel
{
    use Loggable;

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
        $this->log("Picture getLocalPathAttribute:" . $path);
        if (!file_exists($path)) {
            $errorMessage = "File at $path not found, skip";
            $this->log($errorMessage, null);
            Log::warning($errorMessage);
            throw new \Exception($errorMessage);
        }
        if (!$this->isImage($path)) {
            $errorMessage = "File at $path is not image, skip";
            $this->log($errorMessage, null);
            Log::warning($errorMessage);
            throw new \Exception($errorMessage);
        }
        $this->log("Picture size:" . filesize($path));

        return $path;
    }

    public function getDefaultAttribute()
    {
        return public_path() . env('SHOP_DEFAULT_PICTURE_PATH', null);
    }

    private function isImage(string $path)
    {
        $allowedMimeTypes = ['image/jpeg', 'image/gif', 'image/png'];
        $contentType = mime_content_type($path);

        return in_array($contentType, $allowedMimeTypes);
    }
}
