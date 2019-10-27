<?php

namespace App\Services;

use CURLFile;
use DOMDocument;
use Exception;
use Illuminate\Routing\Pipeline;
use VK\Client\VKApiClient;
use App\Token;
use App\Settings;
use App\Category;
use App\Offer;
use App\Picture;
use App\Services\VKAuthService;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;
use VK\Exceptions;

class VKSynchronizerService
{
    private $dom;
    private $group;
    private $token;
    private $VKApiClient;

    public function __construct()
    {
        $this->VKApiClient = new VKApiClient();
    }

    public function loadAllToVK()
    {
        $canLoadToVK = $this->checkAbilityOfLoading();
        if(!$canLoadToVK) return;

        $this->loadAddedPhotosToVK();
        $this->loadAddedCategoryToVK();
        $this->loadAddedOffersToVK();
    }

    private function loadAddedPhotosToVK()
    {
        try{
            $result = $this->VKApiClient->photos()->getMarketUploadServer($this->token, [
                'group_id' => $this->group,
                'main_photo' => 1
            ]);
            sleep(1);
        } catch (Exception $e) {
            Log::critical('getMarketUploadServer: '.$e->getMessage());
            return false;
        }

        $uploadUrl = $result['upload_url'];

        foreach ($this->getAvailablePicturesToUpload() as $picture)
        {
            $resultArray = $this->uploadPictureToServer($uploadUrl, $picture);
            sleep(1);

            if($resultArray) {
                try{
                    $resultArray['group_id'] = $this->group;
                    $result = $this->VKApiClient->photos()->saveMarketPhoto($this->token, $resultArray);
                    sleep(1);
                    $vk_id = $result[0]['id'];

                    $picture->vk_id = $vk_id;
                    $picture->synchronized = true;
                    $picture->synchronize_date = date('Y-m-d H:i:s');
                    $picture->save();
                } catch (Exception $e) {
                    Log::critical('load picture for offer '.$picture->offer->shop_id.': '.$e->getMessage());
                    return false;
                }
            }
        }
    }

    private function uploadPictureToServer($uploadUrl, $picture)
    {
        $ch = curl_init($uploadUrl);

        $path = public_path().'/downloads/' . basename($picture->url);
        $cfile = new CURLFile($path);

        $data = ['file' => $cfile];
        curl_setopt($ch, CURLOPT_POST,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $uploadResult = curl_exec($ch);
        curl_close($ch);

        $decodedResult = json_decode($uploadResult);

        $returnArray = [];

        if(!empty($decodedResult->photo)) {
            $returnArray['server'] = $decodedResult->server;
            $returnArray['photo'] = stripslashes($decodedResult->photo);
            $returnArray['hash'] = $decodedResult->hash;
            if(!empty($decodedResult->crop_data)) {
                $returnArray['crop_data'] = $decodedResult->crop_data;
                $returnArray['crop_hash'] = $decodedResult->crop_hash;
            }

            return $returnArray;
        } else {
            return false;
        }
    }

    private function getAvailablePicturesToUpload()
    {
        $categorySettingsFilter = $this->getCategoriesSettingsFilter();

        $pictures = Picture::whereHas('offer', function (Builder $query) use ($categorySettingsFilter) {
            $query->whereHas('category', function (Builder $query) use ($categorySettingsFilter) {
                $query->whereIn('can_load_to_vk', $categorySettingsFilter);
            });
        })
        ->where('synchronized', false)
        ->where('status', 'added');

        foreach($pictures->cursor() as $picture) {
            yield $picture;
        }

        return $pictures;
    }

    private function loadAddedCategoryToVK()
    {
        $categories = $this->getAvailableCategoriesToUpload();
        foreach($categories as $category) {
            try{
                $paramsArray = [
                    'owner_id' => '-'.$this->group,
                    'title'    => $category->prepared_name,
                    'photo_id' => $category->offers->first()->pictures->first()->vk_id
                ];
                $response = $this->VKApiClient->market()->addAlbum($this->token, $paramsArray);
                $vk_id = $response['market_album_id'];

                $category->vk_id = $vk_id;
                $category->synchronized = true;
                $category->synchronize_date = date('Y-m-d H:i:s');
                $category->save();

                sleep(1);
            } catch(Exception $e) {
                Log::critical('load category '.$category->shop_id.': '.$e->getMessage());
            }
        }
    }

    private function getAvailableCategoriesToUpload()
    {
        $categorySettingsFilter = $this->getCategoriesSettingsFilter();

        $categories = Category::whereIn('can_load_to_vk', $categorySettingsFilter)
        ->where('synchronized', false)
        ->where('status', 'added')
        ->has('offers')
        ->get();

        return $categories;
    }

    private function loadAddedOffersToVK()
    {
        $offers = $this->getAvailableOffersToUpload();

        foreach ($offers as $offer) {
            $picturesIds = $this->prepareOfferPicturesVKIds($offer);

            $paramsArray = [
                'owner_id' => '-'.$this->group,
                'name' => $offer->name,
                'description' => $offer->description,
                'category_id' => 1,
                'price' => $offer->price.'.00',
                'main_photo_id' => $picturesIds['main_picture'],
                'photo_ids' => $picturesIds['pictures']
            ];

            try {
                $response = $this->VKApiClient->market()->add($this->token, $paramsArray);
                sleep(1);
                $offer->vk_id = $response['market_item_id'];
                $offer->synchronized = true;
                $offer->synchronize_date = date('Y-m-d H:i:s');
                $offer->save();
            } catch(Exception $e) {
                Log::critical('load offer '.$offer->shop_id.':'.$e->getMessage());
            }

            $paramsArray = [
                'owner_id' => '-'.$this->group,
                'item_id' => $offer->vk_id,
                'album_ids' => $offer->category->vk_id,
            ];

            try {
                $response = $this->VKApiClient->market()->addToAlbum($this->token, $paramsArray);
            } catch(Exception $e) {
                Log::critical('add to album for offer '.$offer->shop_id.':'.$e->getMessage());
            }
        }
    }

    private function prepareOfferPicturesVKIds($offer)
    {
        $picturesVKIds = $offer->pictures
            ->where('status', 'added')
            ->where('synchronized', true)
            ->pluck('vk_id');

        $picturesVKIds = $picturesVKIds->toArray();

        $mainPicture  = array_shift($picturesVKIds);
        $restPictures = implode(',', $picturesVKIds);

        return [
            'main_picture' => $mainPicture,
            'pictures' => $restPictures
        ];
    }

    private function getAvailableOffersToUpload()
    {
        $categorySettingsFilter = $this->getCategoriesSettingsFilter();

        $offers = Offer::whereHas('category', function (Builder $query) use ($categorySettingsFilter) {
            $query->whereIn('can_load_to_vk', $categorySettingsFilter);
        })
        ->where('synchronized', false)
        ->where('status', 'added')
        ->get();

        return $offers;
    }

    private function getCategoriesSettingsFilter()
    {
        $categorySettingsFilter = [ 'yes' ];
        if(env('SHOP_CAN_LOAD_NEW_DEFAULT', null) == 'yes') {
            $categorySettingsFilter[] = 'default';
        }

        return $categorySettingsFilter;
    }

    private function checkAbilityOfLoading()
    {
        $isTokenSet = $this->setToken();
        if(!$isTokenSet) {
            Log::critical('Токен либо не установлен. Либо не действительный');
            return false;
        }

        $isGroupSet = $this->setGroup();
        if(!$isGroupSet) {
            Log::critical('Нет установленной группы для загрузки фотографий');
            return false;
        }

        return true;
    }

    private function setGroup()
    {
        $group = Settings::where('name', 'group')->first();
        if($group) {
            $this->group = $group->value;
            return true;
        } else {
            return false;
        }
    }

    private function setToken()
    {
        $hasToken = (new VKAuthService())->checkOfflineToken();
        sleep(1);
        if($hasToken) {
            $this->token = Token::first()->token;
            return true;
        }
        return false;
    }

    // Функции для загрузки из файла в БД

    public function processFile()
    {
        $this->initiateDOM();
        $this->processCategoriesNodes();
        $this->processOffersNodes();
    }

    private function initiateDOM()
    {
        $filePath = env('SHOP_IMPORT_FILE_URL', null);
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->load($filePath);
        $this->dom = $dom;
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
        $offer->vendor_code        = $offerNode->getElementsByTagName('vendorCode')[0]->nodeValue;

        $fullDescription = '';
        $fullDescription .= 'Артикул: '.$offer->vendor_code.PHP_EOL;

        $params            = $offerNode->getElementsByTagName('param');
        $paramsText        = '';
        $paramsDescription = '';

        foreach($params AS $paramNode)
        {
            if($paramNode->getAttribute('name') == 'Описание') {
                $paramsDescription = $paramNode->nodeValue;
            } else {
                $paramsText .= $paramNode->getAttribute('name').': '.$paramNode->nodeValue.PHP_EOL;
            }
        }

        $fullDescription .= $paramsText;

        if(isset($offerNode->getElementsByTagName('description')[0])) {
            $nodeDescription = trim($offerNode->getElementsByTagName('description')[0]->nodeValue);
            $fullDescription .= PHP_EOL.$nodeDescription.PHP_EOL;
        } else {
            if($paramsDescription) {
                $fullDescription .= PHP_EOL.$paramsDescription.PHP_EOL;
            }
        }

        $fullDescription .= PHP_EOL.'Пожалуйста, поделитесь ссылкой с друзьями';

        $offer->description = $fullDescription;

        $offer->check_sum = $this->buildOfferCheckSum($offerNode);
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

        $this->downloadFile($pictureNode->nodeValue);
    }

    private function downloadFile($url)
    {
        $path = public_path().'/downloads/' . basename($url);

        $newfname = $path;
        $file = fopen ($url, 'rb');
        if ($file) {
            $newf = fopen ($newfname, 'wb');
            if ($newf) {
                while(!feof($file)) {
                    fwrite($newf, fread($file, 1024 * 8), 1024 * 8);
                }
            }
        }
        if ($file) {
            fclose($file);
        }
        if ($newf) {
            fclose($newf);
        }
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

    private function sleep()
    {
        sleep(1);
    }
}
