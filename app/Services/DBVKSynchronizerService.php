<?php

namespace App\Services;

use App\Traits\RetryTrait;
use Exception;
use Throwable;
use VK\Client\VKApiClient;
use App\Token;
use App\Settings;
use App\Category;
use App\Offer;
use App\Picture;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;

class DBVKSynchronizerService
{
    use RetryTrait;

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
        if (!$canLoadToVK) {
            return;
        }

        $this->loadAddedCategoryToVK();
        $this->loadAddedOffersToVK();
    }

    private function loadAddedCategoryToVK()
    {
        foreach ($this->getAvailableCategoriesToUpload() as $category) {
            try {

                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'title' => $category->prepared_name
                ];
                $pictureItem = $category->picture;

                if(!$pictureItem->vk_id){
                    $pictureItem->vk_id = $this->loadPictureToVK($pictureItem);
                }

                if (!$pictureItem->vk_id) {
                    $category->vk_loading_error = "Category {$category->name}($category->id) hasn't the picture";
                    Log::warning("Category {$category->name}($category->id) hasn't the picture");
                } else {
                    $paramsArray['photo_id'] = $pictureItem->vk_id;
                }

                $token = $this->token;

                $response = $this->retry( function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->addAlbum($token, $paramsArray);
                });

                $category->markAsSynchronized($response['market_album_id']);

            } catch (Exception $e) {
                $category->vk_loading_error .= PHP_EOL.$e->getMessage();
                Log::critical('load category ' . $category->shop_id . ': ' . $e->getMessage());
            }

            $category->save();
        }
    }

    private function loadPictureToVK($picture){
        $token = $this->token;

        try {
            $paramsArray = [
                'group_id' => $this->group,
                'main_photo' => 1
            ];
            $result = $this->retry( function () use ($token, $paramsArray) {
                return $this->VKApiClient->photos()->getMarketUploadServer($token, $paramsArray);
            });
        } catch (Exception $e) {
            $picture->vk_loading_error = $e->getMessage();
            $picture->save();
            Log::critical('getMarketUploadServer: ' . $e->getMessage());

            return false;
        }

        $uploadUrl = $result['upload_url'];

        try {
            $local_path = $picture->local_path;

            $resultArray = $this->retry( function () use ($uploadUrl, $local_path) {
                return $this->VKApiClient->getRequest()->upload($uploadUrl, 'photo', $local_path);
            });
        } catch (Throwable $e) {
            $picture->vk_loading_error = "Picture {$picture->url}($picture->id) wasn't uploaded, upload default one";
            Log::critical("Picture {$picture->url}($picture->id) wasn't uploaded, upload default one");
        }
        if (!isset($resultArray)) {
            try {
                $default = $picture->default;

                $resultArray = $this->retry( function () use ($uploadUrl, $default) {
                    return $this->VKApiClient->getRequest()->upload($uploadUrl, 'photo', $default);
                });
            } catch (Throwable $e) {
                $picture->vk_loading_error = "Default picture {$picture->default}($picture->id) wasn't uploaded, skip";
                $picture->save();
                Log::critical("Default picture {$picture->default}($picture->id) wasn't uploaded, skip");

                return false;
            }
        }

        try {
            $resultArray['group_id'] = $this->group;

            $result = $this->retry( function () use ($token, $resultArray) {
                return $this->VKApiClient->photos()->saveMarketPhoto($token, $resultArray);
            });

            $picture->markAsSynchronized($result[0]['id']);
            $picture->save();

            return $picture->vk_id;
        } catch (Exception $e) {
            $picture->vk_loading_error = $picture->vk_loading_error.PHP_EOL.$e->getMessage();
            $picture->save();
            Log::critical('load picture for offer ' . $picture->offer->shop_id . ': ' . $e->getMessage());

            return false;
        }
    }

    private function getAvailableCategoriesToUpload()
    {
        $categorySettingsFilter = $this->getCategoriesSettingsFilter();

        $categories = Category::whereIn('can_load_to_vk', $categorySettingsFilter)
            ->where('synchronized', false)
            ->where('status', 'added')
            ->has('offers');

        foreach ($categories->cursor() as $category) {
            yield $category;
        }
    }

    private function loadAddedOffersToVK()
    {
        $token = $this->token;

        foreach ($this->getAvailableOffersToUpload() as $offer) {
            $this->loadOfferPictures($offer);
            $picturesIds = $offer->prepareOfferPicturesVKIds();

            $paramsArray = [
                'owner_id' => '-' . $this->group,
                'name' => $offer->name,
                'description' => $offer->description,
                'category_id' => 1,
                'price' => $offer->price . '.00',
                'main_photo_id' => $picturesIds['main_picture'],
                'photo_ids' => $picturesIds['pictures']
            ];

            try {
                $response = $this->retry( function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->add($token, $paramsArray);
                });

                $offer->markAsSynchronized($response['market_item_id']);
            } catch (Exception $e) {
                Log::critical('load offer ' . $offer->shop_id . ':' . $e->getMessage());
                $offer->vk_loading_error = $e->getMessage();
            }

            $offer->save();

            $paramsArray = [
                'owner_id' => '-' . $this->group,
                'item_id' => $offer->vk_id,
                'album_ids' => $offer->category->vk_id,
            ];

            try {
                $this->retry( function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->addToAlbum($token, $paramsArray);
                });
            } catch (Exception $e) {
                Log::critical('add to album for offer ' . $offer->shop_id . ':' . $e->getMessage());
            }
        }
    }

    private function loadOfferPictures($offer)
    {
        $pictures = Picture::where('offer_id', $offer->id)
            ->where('synchronized', false)
            ->where('status', '<>', 'deleted')
            ->get();

        foreach ($pictures as $picture) {
            $this->loadPictureToVK($picture);
        }
    }

    private function getAvailableOffersToUpload()
    {
        $categorySettingsFilter = $this->getCategoriesSettingsFilter();

        $offers = Offer::whereHas('category', function (Builder $query) use ($categorySettingsFilter) {
            $query->whereIn('can_load_to_vk', $categorySettingsFilter);
        })
        ->where('synchronized', false)
        ->where('status', 'added');

        foreach ($offers->cursor() as $offer) {
            yield $offer;
        }
    }

    private function getCategoriesSettingsFilter()
    {
        $categorySettingsFilter = ['yes'];
        if (env('SHOP_CAN_LOAD_NEW_DEFAULT', null) == 'yes') {
            $categorySettingsFilter[] = 'default';
        }

        return $categorySettingsFilter;
    }

    private function checkAbilityOfLoading()
    {
        $isTokenSet = $this->setToken();
        if (!$isTokenSet) {
            Log::critical('Токен либо не установлен. Либо не действительный');
            return false;
        }

        $isGroupSet = $this->setGroup();
        if (!$isGroupSet) {
            Log::critical('Нет установленной группы для загрузки фотографий');
            return false;
        }

        return true;
    }

    private function setGroup()
    {
        $group = Settings::where('name', 'group')->first();
        if ($group) {
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
        if ($hasToken) {
            $this->token = Token::first()->token;
            return true;
        }
        return false;
    }
}
