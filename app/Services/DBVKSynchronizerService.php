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

        $this->processEditedCategories();
        $this->processEditedOffers();

        $this->processDeletedCategories();
        $this->processDeletedOffers();
    }

    private function loadAddedCategoryToVK()
    {
        $token = $this->token;

        foreach ($this->getAvailableCategoriesForSynchronize('added') as $category) {
            try {

                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'title' => $category->prepared_name
                ];
                $pictureItem = $category->picture;

                if(!$pictureItem->vk_id){
                    $pictureItem->vk_id = $this->loadPictureToVK($pictureItem);

                    if (!$pictureItem->vk_id) {
                        $category->vk_loading_error = "Category {$category->name}($category->id) hasn't the picture";
                        Log::warning("Category {$category->name}($category->id) hasn't the picture");
                    } else {
                        $paramsArray['photo_id'] = $pictureItem->vk_id;
                    }
                } else {
                    $pictureInAnotherCategory = Category::where('picture_vk_id', $pictureItem->vk_id)->count();
                    if($pictureInAnotherCategory) {
                        $vk_id = $this->loadPictureToVK($pictureItem, true);
                        if (!$vk_id) {
                            $category->vk_loading_error = "Category {$category->name}($category->id) hasn't the picture";
                            Log::warning("Category {$category->name}($category->id) hasn't the picture");
                        } else {
                            $paramsArray['photo_id'] = $vk_id;
                        }
                    }
                }

                if(isset($paramsArray['photo_id']) && $paramsArray['photo_id']) {
                    $category->picture_vk_id = $paramsArray['photo_id'];
                }

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

    private function loadPictureToVK($picture, $duplicate = false){
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
            $picture->vk_loading_error = "Picture {$picture->url}($picture->id) wasn't uploaded, upload default one: {$e->getMessage()}";
            Log::critical("Picture {$picture->url}($picture->id) wasn't uploaded, upload default one: {$e->getMessage()}");
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

            if(!$duplicate) {
                $picture->markAsSynchronized($result[0]['id']);
                $picture->save();

                return $picture->vk_id;
            } else {
                return $result[0]['id'];
            }
        } catch (Exception $e) {
            $picture->vk_loading_error = $picture->vk_loading_error.PHP_EOL.$e->getMessage();
            $picture->save();
            Log::critical('load picture for offer ' . $picture->offer->shop_id . ': ' . $e->getMessage());

            return false;
        }
    }

    private function getAvailableCategoriesForSynchronize($status)
    {
        $categorySettingsFilter = $this->getCategoriesSettingsFilter();

        $categories = Category::whereIn('can_load_to_vk', $categorySettingsFilter)
            ->where('synchronized', false)
            ->where('status', $status);

        if($status == 'added') {
            $categories->has('offers');
        }

        foreach ($categories->cursor() as $category) {
            yield $category;
        }
    }

    private function loadAddedOffersToVK()
    {
        $token = $this->token;

        foreach ($this->getAvailableOffersForSynchronize('added') as $offer) {
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

    private function getAvailableOffersForSynchronize($status)
    {
        $categorySettingsFilter = $this->getCategoriesSettingsFilter();

        $offers = Offer::whereHas('category', function (Builder $query) use ($categorySettingsFilter) {
            $query->whereIn('can_load_to_vk', $categorySettingsFilter);
        })
        ->where('synchronized', false)
        ->where('status', $status);

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

    // functions for update

    private function processEditedCategories()
    {
        $token = $this->token;

        foreach ($this->getAvailableCategoriesForSynchronize('edited') as $category) {
            try {
                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'album_id' => $category->vk_id,
                    'title' => $category->prepared_name
                ];

                $response = $this->retry( function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->editAlbum($token, $paramsArray);
                });

                if($response) {
                    $category->markAsSynchronized();
                } else {
                    $errorText = 'edit category ' . $category->shop_id . ': something went wrong';
                    $category->vk_loading_error .= PHP_EOL.$errorText;
                    Log::critical($errorText);
                }
            } catch (Exception $e) {
                $category->vk_loading_error .= PHP_EOL.$e->getMessage();
                Log::critical('edit category ' . $category->shop_id . ': ' . $e->getMessage());
            }

            $category->save();
        }
    }

    private function processEditedOffers()
    {
        $token = $this->token;

        foreach ($this->getAvailableOffersForSynchronize('edited') as $offer) {
            $this->loadOfferPictures($offer);
            $picturesIds = $offer->prepareOfferPicturesVKIds();

            $paramsArray = [
                'owner_id' => '-' . $this->group,
                'item_id' => $offer->vk_id,
                'name' => $offer->name,
                'description' => $offer->description,
                'category_id' => 1,
                'price' => $offer->price . '.00',
                'main_photo_id' => $picturesIds['main_picture'],
                'photo_ids' => $picturesIds['pictures']
            ];

            try {
                $response = $this->retry( function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->edit($token, $paramsArray);
                });

                $offer->markAsSynchronized($response['market_item_id']);
            } catch (Exception $e) {
                Log::critical('load offer ' . $offer->shop_id . ':' . $e->getMessage());
                $offer->vk_loading_error = $e->getMessage();
            }

            $offer->save();

            if($offer->shop_category_id == $offer->shop_old_category_id) {
                continue;
            }

            $paramsArray = [
                'owner_id' => '-' . $this->group,
                'item_id' => $offer->vk_id,
            ];

            $paramsArray['album_ids'] = $offer->oldcategory->vk_id;

            try {
                $this->retry( function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->removeFromAlbum($token, $paramsArray);
                });
            } catch (Exception $e) {
                Log::critical('remove to album for offer ' . $offer->shop_id . ':' . $e->getMessage());
            }

            $paramsArray['album_ids'] = $offer->category->vk_id;

            try {
                $this->retry( function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->addToAlbum($token, $paramsArray);
                });
            } catch (Exception $e) {
                Log::critical('add to album for offer ' . $offer->shop_id . ':' . $e->getMessage());
            }
        }
    }

    private function deletePhotos()
    {
        $token = $this->token;

        foreach ($this->getPhotosForDelete() as $photo) {
            $paramsArray = [
                'owner_id' => '-'.$this->group,
                'photo_id' => $photo->vk_id
            ];

            try {
                $response = $this->retry( function () use ($token, $paramsArray) {
                    return $this->VKApiClient->photos()->delete($token, $paramsArray);
                });

                if(!$response) continue;

                $photo->markAsSynchronized();
            } catch (Exception $e) {
                Log::critical('delete photo ' . $photo->id . ':' . $e->getMessage());
                $photo->vk_loading_error = 'delete photo ' . $photo->id . ':' . $e->getMessage();
            }
        }
    }

    private function getPhotosForDelete()
    {
        $categorySettingsFilter = $this->getCategoriesSettingsFilter();

        $pictures = Picture::whereHas('offer', function (Builder $query) use ($categorySettingsFilter) {
            $query->whereHas('category', function (Builder $query) use ($categorySettingsFilter) {
                $query->whereIn('can_load_to_vk', $categorySettingsFilter);
            });
        })

        ->where('synchronized', false)
        ->where('status', 'deleted')
        ->whereNotIn('vk_id', function ($query) {
            $query->select('picture_vk_id')->from(with(new Category)->getTable())
                ->where('picture_vk_id', '<>', 0);
        });

        foreach ($pictures->cursor() as $picture) {
            yield $picture;
        }
    }

    private function processDeletedCategories()
    {
        $token = $this->token;

        foreach ($this->getAvailableCategoriesForSynchronize('deleted') as $category) {
            if(!$category->vk_id) continue;
            try {
                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'album_id' => $category->vk_id,
                ];

                $response = $this->retry( function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->deleteAlbum($token, $paramsArray);
                });

                if($response) {
                    $category->markAsSynchronized();
                } else {
                    $errorText = 'delete category ' . $category->shop_id . ': something went wrong';
                    $category->vk_loading_error .= PHP_EOL.$errorText;
                    Log::critical($errorText);
                }
            } catch (Exception $e) {
                $category->vk_loading_error .= PHP_EOL.$e->getMessage();
                Log::critical('delete category ' . $category->shop_id . ': ' . $e->getMessage());
            }

            $category->save();
        }
    }

    private function processDeletedOffers()
    {
        $token = $this->token;

        foreach ($this->getAvailableOffersForSynchronize('deleted') as $offer) {
            if(!$offer->vk_id) continue;
            try {
                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'item_id' => $offer->vk_id,
                ];

                $response = $this->retry( function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->delete($token, $paramsArray);
                });

                if($response) {
                    $offer->markAsSynchronized();
                } else {
                    $errorText = 'delete offer ' . $offer->shop_id . ': something went wrong';
                    $offer->vk_loading_error .= PHP_EOL.$errorText;
                    Log::critical($errorText);
                }
            } catch (Exception $e) {
                $offer->vk_loading_error .= PHP_EOL.$e->getMessage();
                Log::critical('delete offer ' . $offer->shop_id . ': ' . $e->getMessage());
            }

            $offer->save();
        }
    }
}
