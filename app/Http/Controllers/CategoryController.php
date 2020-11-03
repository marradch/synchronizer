<?php

namespace App\Http\Controllers;

use App\Category;
use App\Offer;

class CategoryController extends Controller
{
    public function __construct()
    {
    }

    public function index()
    {
        return view('category.index');
    }

    public function getCategories()
    {
        $categories = Category::query()
            ->where('status', '<>', 'deleted')
            ->paginate(100)
            ->appends(['fullname']);

        $categories->map(
            function ($item) {
                $item->full_name = $item->buildFullName();
                return $item;
            }
        );

        return $categories->toJson();
    }

    public function setLoadToVKYes($ids)
    {
        $availableCount = 100;

        $ids = explode('-', $ids);

        $count = Category::where('can_load_to_vk', 'yes')->count();

        $excludeIds = [];

        foreach ($ids as $id) {
            $count++;
            if ($count > $availableCount) {
                continue;
            }
            Category::find($id)->update(['can_load_to_vk' => 'yes']);
            $excludeIds[] = $id;
        }

        $rest = array_diff($ids, $excludeIds);
        $rest = array_values($rest);

        return response()->json($rest);
    }

    public function setLoadToVKNo($ids)
    {
        $ids = explode('-', $ids);
        Category::whereIn('id', $ids)->update(
            [
                'can_load_to_vk' => 'no',
                'status' => 'deleted',
                'synchronized' => false
            ]
        );
        $shopIds = Category::whereIn('id', $ids)->get()->pluck('shop_id')->all();

        Offer::whereIn('status', ['added', 'edited'])
            ->whereIn('shop_category_id', $shopIds)
            ->update(
                [
                    'status' => 'deleted',
                    'synchronized' => false
                ]
            );
    }

    public function getSelectedCount()
    {
        $count = Category::where('can_load_to_vk', 'yes')->count();
        return response()->json(['count' => $count]);
    }
}
