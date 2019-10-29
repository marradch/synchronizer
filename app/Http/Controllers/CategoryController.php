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
        $categories = Category::paginate(4);

        return $categories->toJson();
    }

    public function setLoadToVKYes($id)
    {
        Category::find($id)->update(['can_load_to_vk' => 'yes']);
    }

    public function setLoadToVKNo($id)
    {
        Category::find($id)->update(['can_load_to_vk' => 'no']);
    }
}
