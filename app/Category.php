<?php

namespace App;

use App\Traits\Loggable;

class Category extends SynchronizedModel
{
    use Loggable;

    const MIN_CATEGORY_IMAGE_WIDTH = 1280;
    const MIN_CATEGORY_IMAGE_HEIGHT = 720;

    protected $fillable = [
        'shop_id',
        'shop_parent_id',
        'name',
        'prepared_name',
        'is_final',
        'check_sum',
        'delete_sign',
        'status',
        'status_date',
        'synchronized',
        'synchronize_date',
        'vk_id',
        'vk_loading_error',
        'can_load_to_vk',
        'picture_vk_id',
    ];

    protected $table = 'categories';

    public function isFinal()
    {
        return !$this->children->count();
    }

    public function children()
    {
        return $this->hasMany('App\Category', 'shop_parent_id', 'shop_id');
    }

    public function parent()
    {
        return $this->belongsTo('App\Category', 'shop_parent_id', 'shop_id');
    }

    public function offers()
    {
        return $this->hasMany('App\Offer', 'shop_category_id', 'shop_id');
    }

    public function buildFullName()
    {
        $parents = $this->buildParentsArray();
        $parents = array_reverse($parents);

        $preparedNameArray = [];

        foreach ($parents as $parent) {
            $preparedNameArray[] = $parent->name;
        }

        $preparedNameArray[] = $this->name;

        return implode('/', $preparedNameArray);
    }

    public function prepareName()
    {
        $parents = $this->buildParentsArray();
        $parents = array_reverse($parents);

        $specCategoryId = env('SHOP_SPEC_CATEGORY', 0);

        $preparedIdsArray = [];

        foreach ($parents as $parent) {
            $preparedIdsArray[] = $parent->shop_id;
        }

        $specIdx = array_search($specCategoryId, $preparedIdsArray);

        if ($specIdx) {
            $preparedNameArray = [];

            foreach ($parents as $idx => $parent) {
                if ($idx < $specIdx) {
                    continue;
                }
                $preparedNameArray[] = $parent->name;
            }

            $this->prepared_name = implode('/', $preparedNameArray) . '/' . $this->name;
        } else {
            $this->prepared_name = $this->name;
        }
    }

    public function buildParentsArray($parentsArray = [])
    {
        if ($this->parent) {
            $parentsArray[] = $this->parent;
            $parentsArray = $this->parent->buildParentsArray($parentsArray);
        }

        return $parentsArray;
    }

    /**
     * @param $category
     * @return mixed
     */
    public function getPictureAttribute()
    {
        $dimensions = self::MIN_CATEGORY_IMAGE_WIDTH . "x" . self::MIN_CATEGORY_IMAGE_HEIGHT;
        $this->log("Category getPictureAttribute, looking for " . $dimensions);
        foreach ($this->offers as $offer) {
            foreach ($offer->pictures as $picture) {
                if ($picture->status === 'deleted') {
                    continue;
                }
                $path = $picture->local_path;
                $imagedetails = getimagesize($path);
                $width = $imagedetails[0];
                $height = $imagedetails[1];
                $this->log("Picture {$picture->local_path}: {$width}x{$height}");
                if (($width >= self::MIN_CATEGORY_IMAGE_WIDTH) && ($height >= self::MIN_CATEGORY_IMAGE_HEIGHT)) {
                    return $picture;
                }
            }
        }

        $error = "Category getPictureAttribute could not find suitable photo " . $dimensions;
        $this->log($error);
        throw new \Exception($error);
    }
}
