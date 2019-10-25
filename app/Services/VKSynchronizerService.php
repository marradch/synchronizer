<?php

namespace App\Services;

use DOMDocument;
use Illuminate\Routing\Pipeline;
use VK\Client\VKApiClient;
use App\Token;
use App\Settings;
use App\Category;
use App\Offer;
use App\Picture;

class VKSynchronizerService
{
    private $dom;

    public function __construct()
    {
        $this->initiateDOM();
    }

    private function initiateDOM()
    {
        $filePath = env('SHOP_IMPORT_FILE_URL', null);
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->load($filePath);
        $this->dom = $dom;
    }

    public function processFile()
    {
        $this->processCategoriesNodes();
        $this->processOffersNodes();

    }

    public function processCategoriesNodes()
    {
        $categories = $this->dom->getElementsByTagName('category');

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
            $this->setDeleteStatus($category);
        }

        $this->postLoadingCategoriesProcess();
    }

    public function processOffersNodes()
    {
        $offers = $this->dom->getElementsByTagName('offer');
        $allIds = [];
        foreach($offers AS $offerNode)
        {
            $shop_id = $offerNode->getAttribute('id');
            $allIds[] = $shop_id;

            $offer = Offer::where('shop_id', $shop_id)->first();

            if($offer) {
                $this->editOffer($offer, $offerNode);
            } else {
                $this->addOffer($offerNode);
            }
        }

        $deletedOffers = Offer::whereNotIn('shop_id', $allIds)->get();
        foreach($deletedOffers as $offer) {
            $this->setDeleteStatus($offer);
        }
    }

    private function addOffer($offerNode)
    {
        $price = (int) $offerNode->getElementsByTagName('price')[0]->nodeValue;
        if($price == 0) return;

        $offer               = new Offer();

        $this->fillOfferFromNode($offer, $offerNode);

        $offer->status       = 'added';
        $offer->status_date  = date('Y-m-d H:i:s');
        $offer->synchronized = false;
        $offer->save();

        $pictures = $offerNode->getElementsByTagName('picture');
        foreach($pictures AS $pictureNode)
        {
            $this->addPicture($offer->id, $pictureNode);
        }
    }

    private function editOffer($offer, $offerNode)
    {
        $currentCheckSum = $this->buildOfferCheckSum($offerNode);
        if($currentCheckSum == $offer->check_sum) return;

        $this->fillOfferFromNode($offer, $offerNode);

        $offer->status       = 'edited';
        $offer->status_date  = date('Y-m-d H:i:s');
        $offer->synchronized = false;
        $offer->save();

        $this->actualizePictures($offer, $offerNode);
    }

    private function buildOfferCheckSum($offerNode)
    {
        $checkSumArray = [];
        $checkSumArray[]     = $offerNode->getElementsByTagName('categoryId')[0]->nodeValue;
        $checkSumArray[]     = $offerNode->getElementsByTagName('name')[0]->nodeValue;
        $checkSumArray[]     = $offerNode->getElementsByTagName('price')[0]->nodeValue;;
        $checkSumArray[]     = $offerNode->getElementsByTagName('name')[0]->nodeValue;
        $params = $offerNode->getElementsByTagName('param');
        foreach($params AS $paramNode)
        {
            $checkSumArray[] = $paramNode->getAttribute('name');
            $checkSumArray[] = $paramNode->nodeValue;
        }
        $checkSumArray[] = $offerNode->getElementsByTagName('description')[0]->nodeValue;
        $pictures = $offerNode->getElementsByTagName('picture');
        foreach($pictures AS $picture)
        {
            $checkSumArray[] = $picture->nodeValue;
        }

        return md5(serialize($checkSumArray));
    }

    private function fillOfferFromNode($offer, $offerNode)
    {
        $offer->shop_id            = $offerNode->getAttribute('id');
        $offer->shop_category_id   = $offerNode->getElementsByTagName('categoryId')[0]->nodeValue;
        $offer->name               = $offerNode->getElementsByTagName('name')[0]->nodeValue;
        $offer->price              = $offerNode->getElementsByTagName('price')[0]->nodeValue;
        $offer->vendor_code        = $offerNode->getElementsByTagName('name')[0]->nodeValue;

        $paramsText = '';

        $params = $offerNode->getElementsByTagName('param');
        foreach($params AS $paramNode)
        {
            $paramsText .= $paramNode->getAttribute('name').': '.$paramNode->nodeValue.'\n';
        }

        $description = trim($offerNode->getElementsByTagName('description')[0]->nodeValue);

        $offer->description = $paramsText.'\n'.$description;

        $offer->check_sum          = $this->buildOfferCheckSum($offerNode);
    }

    private function actualizePictures($offer, $offerNode)
    {
        $pictures = $offerNode->getElementsByTagName('picture');

        $actualUrls = [];

        foreach($pictures AS $pictureNode)
        {
            $actualUrls[] = trim($pictureNode->nodeValue);

            $picture = Picture::where([
                ['offer_id', '=', $offer->id],
                ['url', '=', $pictureNode->nodeValue],
                ['status', '<>', 'deleted'],
            ])->first();

            if(!$picture) {
                $this->addPicture($offer->id, $pictureNode);
            }
        }

        $deletedPictures = Picture::where([
            ['offer_id', '=', $offer->id],
            ['status', '<>', 'deleted']
        ])->whereNotIn('url', $actualUrls)->get();

        foreach($deletedPictures as $picture) {
            $this->setDeleteStatus($picture);
        }
    }

    private function addPicture($offerId, $pictureNode)
    {
        $picture = new Picture();
        $picture->offer_id     = $offerId;
        $picture->url          = trim($pictureNode->nodeValue);
        $picture->status       = 'added';
        $picture->status_date  = date('Y-m-d H:i:s');
        $picture->synchronized = false;
        $picture->save();
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

    private function setDeleteStatus($item)
    {
        $item->status       = 'deleted';
        $item->status_date  = date('Y-m-d H:i:s');
        $item->synchronized = false;
        $item->save();
    }
}
