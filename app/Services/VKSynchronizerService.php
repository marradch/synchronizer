<?php

namespace App\Services;

use VK\Client\VKApiClient;
use App\Token;
use App\Settings;
use App\Category;

class VKSynchronizerService
{
    public function processFile()
    {
        $filePath = env('SHOP_IMPORT_FILE_URL', null);
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->load($filePath);

        $categories = $dom->getElementsByTagName('category');

        $allIds = [];
        foreach($categories AS $categoryNode)
        {
            $shop_id = $categoryNode->getAttribute('id');
            $allIds[] = $shop_id;

            $category = Category::where('shop_id', $shop_id)->first();

            if($category) {
                $this->editCategory($category, $categoryNode);
            } else {
                $this->addCategory($categoryNode);
            }
        }

        $deletedCategories = Category::whereNotIn('shop_id', $allIds)->get();
        foreach($deletedCategories as $category) {
            $this->deleteCategory($category);
        }

        $this->postLoadingCategoriesProcess();
    }

    private function postLoadingCategoriesProcess()
    {
        $categories = Category::all();
        foreach ($categories as $category) {

            if($category->status == 'deleted' || $category->synchronized) continue;

            $category->prepareName();
            $category->save();
        }
    }

    private function addCategory($categoryNode)
    {
        $category                     = new Category();
        $category->shop_id            = $categoryNode->getAttribute('id');
        $parentId                     = $categoryNode->getAttribute('parentId');
        if($parentId) {
            $category->shop_parent_id = $categoryNode->getAttribute('parentId');
        }
        $category->name               = $categoryNode->nodeValue;
        $category->check_sum          = md5($category->shop_parent_id
            .$category->name);
        $category->status             = 'added';
        $category->status_date        = date('Y-m-d H:i:s');
        $category->synchronized       = false;
        $category->save();
    }

    private function editCategory($category, $categoryNode)
    {
        $parentToCheck     = ($category->shop_parent_id) ? $categoryNode->getAttribute('parentId') : '';
        $current_check_sum = md5($parentToCheck
                           .$category->name);
        $new_check_sum     = md5($categoryNode->getAttribute('parentId')
                           .$categoryNode->nodeValue);
        if($current_check_sum != $new_check_sum) {
            $category->name               = $categoryNode->nodeValue;
            $parentId                     = $categoryNode->getAttribute('parentId');
            if ($parentId) {
                $category->shop_parent_id = $categoryNode->getAttribute('parentId');
            } else {
                $category->shop_parent_id = 0;
            }
            $category->check_sum          = $new_check_sum;
            $category->status             = 'edited';
            $category->status_date        = date('Y-m-d H:i:s');
            $category->synchronized       = false;
            $category->save();
        }
    }

    private function deleteCategory($category)
    {
        $category->status       = 'deleted';
        $category->status_date  = date('Y-m-d H:i:s');
        $category->synchronized = false;
        $category->save();
    }
}
