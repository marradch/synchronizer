<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'shop_id',
        'shop_parent_id',
        'vk_id',
        'name',
        'prepared_name',
        'is_final',
        'check_sum',
        'status',
        'status_date',
        'synchronized',
        'synchronize_date',
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

    public function buildFullName()
    {
        $parents = $this->buildParentsArray();
        $parents = array_reverse($parents);

        $preparedNameArray = [];

        foreach ($parents as $parent) {
            $preparedNameArray[] = $parent->name;
        }

        $preparedNameArray[] = $this->name;

        $this->prepared_name = implode('/', $preparedNameArray);
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

        if($specIdx) {
            $preparedNameArray = [];

            foreach ($parents as $idx => $parent) {
                if($idx < $specIdx) continue;
                $preparedNameArray[] = $parent->name;
            }

            $this->prepared_name = implode('/', $preparedNameArray).'/'.$this->name;
        } else {
            $this->prepared_name = $this->name;
        }
    }

    public function buildParentsArray($parentsArray = [])
    {
        if($this->parent) {
            $parentsArray[] = $this->parent;
            $parentsArray = $this->parent->buildParentsArray($parentsArray);
        }

        return $parentsArray;
    }
}
