<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Category;

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
        $categories = Category::paginate(20);

        return $categories->toJson();
    }

    public function setLoadToVKYes($ids)
    {
        $availableCount = 100;

        $ids = explode('-', $ids);

        $count = Category::where('can_load_to_vk', 'yes')->count();

        $excludeIds = [];

        foreach($ids as $id) {
            $count++;
            if($count > $availableCount) continue;
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
        Category::whereIn('id', $ids)->update(['can_load_to_vk' => 'no']);
    }

    public function getSelectedCount()
    {
        $count = Category::where('can_load_to_vk', 'yes')->count();
        return response()->json(['count' => $count]);
    }
}
