<?php

namespace App;

class Offer extends SynchronizedModel
{
    protected $fillable = [
        'shop_id',
        'shop_category_id',
        'shop_old_category_id',
        'name',
        'price',
        'vendor_code',
        'params',
        'origin_description',
        'description',
        'check_sum',
        'delete_sign',
        'is_excluded',
        'is_aggregate',
        'synch_with_aggregate',
        'status',
        'status_date',
        'synchronized',
        'synchronize_date',
        'vk_id',
        'vk_loading_error',
    ];

    protected $table = 'offers';

    public function category()
    {
        return $this->belongsTo('App\Category', 'shop_category_id', 'shop_id');
    }

    public function oldcategory()
    {
        return $this->belongsTo('App\Category', 'shop_old_category_id', 'shop_id');
    }

    public function pictures()
    {
        return $this->hasMany('App\Picture', 'offer_id', 'id');
    }

    public function prepareOfferPicturesVKIds()
    {
        $picturesVKIds = $this->pictures
            ->where('status', 'added')
            ->where('synchronized', true)
            ->pluck('vk_id');

        $picturesVKIds = $picturesVKIds->toArray();

        $mainPicture = array_shift($picturesVKIds);
        $shortArray = [];
        while(count($shortArray) < 4
            && count($picturesVKIds)) {
            $shortArray[] = array_shift($picturesVKIds);
        }
        $restPictures = implode(',', $shortArray);

        return [
            'main_picture' => $mainPicture,
            'pictures' => $restPictures
        ];
    }

    public function turnDeletedStatus()
    {
        if ($this->status != 'deleted') return;

        $this->status = 'added';
        $this->synch_with_aggregate = false;

        if ($this->synchronized) {
            $this->vk_id = 0;
            $this->synchronized = false;

            Picture::where('offer_id', $this->id)->update([
                'vk_id' => 0,
                'status' => 'added',
                'synchronized' => false,
            ]);
        } else {
            if ($this->vk_id) {
                $this->synchronized = true;
            }
        }
        $this->save();
    }
}
