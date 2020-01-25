<?php

namespace App\Services;

use App\Traits\RetryTrait;
use App\Traits\LaunchableTrait;
use Exception;
use Throwable;
use VK\Client\VKApiClient;
use App\Category;
use App\Offer;
use App\Picture;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;

class DBVKSynchronizerService
{
    use RetryTrait;
    use LaunchableTrait;

    protected $group;
    protected $token;
    protected $VKApiClient;

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

        echo "start loading to VK\n";

        $this->loadAddedCategoryToVK();
        $this->loadAddedOffersToVK();

        $this->processEditedCategories();
        $this->processEditedOffers();

        $this->processDeletedCategories();
        $this->processDeletedOffers();

        echo "end loading to VK\n";
    }

    private function loadAddedCategoryToVK()
    {
        $token = $this->token;

        echo "start to add categories\n";

        foreach ($this->getAvailableCategoriesForSynchronize('added') as $category) {

            echo "start to add category {$category->id}\n";

            try {

                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'title' => $category->prepared_name
                ];
                $pictureItem = $category->picture;

                if (!$pictureItem->vk_id) {
                    $pictureItem->vk_id = $this->loadPictureToVK($pictureItem);

                    if (!$pictureItem->vk_id) {
                        $mess = "Category {$category->name}($category->id) hasn't the picture\n";
                        $category->vk_loading_error = $mess;
                        Log::warning($mess);
                        echo $mess;
                    } else {
                        $paramsArray['photo_id'] = $pictureItem->vk_id;
                    }
                } else {
                    $pictureInAnotherCategory = Category::where('picture_vk_id', $pictureItem->vk_id)->count();
                    if ($pictureInAnotherCategory) {
                        $vk_id = $this->loadPictureToVK($pictureItem, true);
                        if (!$vk_id) {
                            $mess = "Category {$category->name}($category->id) hasn't the picture\n";
                            $category->vk_loading_error = $mess;
                            Log::warning($mess);
                            echo $mess;
                        } else {
                            $paramsArray['photo_id'] = $vk_id;
                        }
                    }
                }

                if (isset($paramsArray['photo_id']) && $paramsArray['photo_id']) {
                    $category->picture_vk_id = $paramsArray['photo_id'];
                }

                $response = $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->addAlbum($token, $paramsArray);
                });

                $category->markAsSynchronized($response['market_album_id']);

            } catch (Exception $e) {
                $mess = "error to load category {$category->shop_id}: {$e->getMessage()}\n";
                $category->vk_loading_error = $mess;
                Log::critical($mess);
                echo $mess;
            }

            $category->save();

            echo "end process category {$category->id}\n";
        }
        echo "end to add categories\n";
    }

    private function loadPictureToVK($picture, $duplicate = false)
    {
        echo "start load picture {$picture->id}\n";

        $token = $this->token;
        try {
            $paramsArray = [
                'group_id' => $this->group,
                'main_photo' => 1
            ];
            $result = $this->retry(function () use ($token, $paramsArray) {
                return $this->VKApiClient->photos()->getMarketUploadServer($token, $paramsArray);
            });
        } catch (Exception $e) {
            $mess = 'error in getMarketUploadServer: ' . $e->getMessage()."\n";
            $picture->vk_loading_error = $mess;
            $picture->save();
            Log::critical($mess);
            echo $mess;

            return false;
        }

        $uploadUrl = $result['upload_url'];

        try {
            $local_path = $picture->local_path;
            $resultArray = $this->retry(function () use ($uploadUrl, $local_path) {
                return $this->VKApiClient->getRequest()->upload($uploadUrl, 'photo', $local_path);
            });
        } catch (Throwable $e) {
            $mess = "Picture {$picture->url}($picture->id) wasn't uploaded: {$e->getMessage()}\n";
            $picture->vk_loading_error = $mess;
            $picture->save();
            Log::critical($mess);
            return false;
        }

        try {
            $resultArray['group_id'] = $this->group;
            $result = $this->retry(function () use ($token, $resultArray) {
                return $this->VKApiClient->photos()->saveMarketPhoto($token, $resultArray);
            });

            echo "end load picture {$picture->id}\n";

            if (!$duplicate) {
                $picture->markAsSynchronized($result[0]['id']);
                $picture->save();

                return $picture->vk_id;
            } else {
                return $result[0]['id'];
            }
        } catch (Exception $e) {
            $mess = "error to load picture {$picture->id}: {$e->getMessage()}\n";
            $picture->vk_loading_error = $mess;
            $picture->save();
            Log::critical($mess);
            echo $mess;
            return false;
        }
    }

    private function getAvailableCategoriesForSynchronize($status)
    {
        $categorySettingsFilter = $this->getCategoriesSettingsFilter();

        $categories = Category::whereIn('can_load_to_vk', $categorySettingsFilter)
            ->where('synchronized', false)
            ->where('status', $status);

        if ($status == 'added') {
            $categories->has('offers');
        }

        foreach ($categories->cursor() as $category) {
            yield $category;
        }
    }

    private function loadAddedOffersToVK()
    {
        echo "start to add offers\n";

        $token = $this->token;

        /** @var Offer $offer */
        foreach ($this->getAvailableOffersForSynchronize('added') as $offer) {
            echo "start to add offer {$offer->id}\n";
            $this->loadOfferPictures($offer);
            $picturesIds = $offer->prepareOfferPicturesVKIds();
            if (!$picturesIds['main_picture']) {
                $mess = "main picture for {$offer->id} is missing, skip loading\n";
                Log::critical($mess);
                echo $mess;
                $offer->vk_loading_error = $mess;
                $offer->save();
                continue;
            }
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
                $response = $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->add($token, $paramsArray);
                });
                $offer->markAsSynchronized($response['market_item_id']);
            } catch (Exception $e) {
                $mess = "error to add offer {$offer->id}: {$e->getMessage()}\n";
                Log::critical($mess);
                $offer->vk_loading_error = $mess;
                echo $mess;
            }

            $paramsArray = [
                'owner_id' => '-' . $this->group,
                'item_id' => $offer->vk_id,
                'album_ids' => $offer->category->vk_id,
            ];

            try {
                $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->addToAlbum($token, $paramsArray);
                });
            } catch (Exception $e) {
                $mess = "add to album for offer {$offer->id}: {$e->getMessage()}\n";
                Log::critical($mess);
                echo $mess;
                $offer->vk_loading_error = $mess;
            }

            $offer->save();

            echo "end to add offer {$offer->id}\n";
        }

        echo "end to add offers\n";
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
        ->where('status', $status)
        ->where('is_excluded', false)
        ->orderBy('shop_category_id');

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

    // functions for update

    private function processEditedCategories()
    {
        $token = $this->token;

        echo "start to edit categories\n";

        foreach ($this->getAvailableCategoriesForSynchronize('edited') as $category) {

            echo "start to edit category {$category->id}\n";

            try {
                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'album_id' => $category->vk_id,
                    'title' => $category->prepared_name
                ];

                $response = $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->editAlbum($token, $paramsArray);
                });

                $category->markAsSynchronized();
            } catch (Exception $e) {
                $mess = "error to edit category {$category->shop_id}: {$e->getMessage()}\n";
                $category->vk_loading_error = $mess;
                Log::critical($mess);
                echo $mess;
            }

            $category->save();
            echo "end to edit category {$category->id}\n";
        }
        echo "end to edit categories\n";
    }

    private function processEditedOffers()
    {
        echo "start to edit offers\n";
        $token = $this->token;

        foreach ($this->getAvailableOffersForSynchronize('edited') as $offer) {
            echo "start to edit offer {$offer->id}\n";
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
                $response = $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->edit($token, $paramsArray);
                });

                $offer->markAsSynchronized($response['market_item_id']);
            } catch (Exception $e) {
                $mess = "error to load offer {$offer->id}: {$e->getMessage()}\n";
                Log::critical($mess);
                $offer->vk_loading_error = $mess;
                echo $mess;
            }

            if ($offer->shop_category_id == $offer->shop_old_category_id || !$offer->shop_old_category_id) {
                continue;
            }

            $paramsArray = [
                'owner_id' => '-' . $this->group,
                'item_id' => $offer->vk_id,
            ];

            $paramsArray['album_ids'] = $offer->oldcategory->vk_id;

            try {
                $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->removeFromAlbum($token, $paramsArray);
                });
            } catch (Exception $e) {
                $mess = "error to remove to album for offer {$offer->id}: {$e->getMessage()}\n";
                Log::critical($mess);
                $offer->vk_loading_error = $mess;
                echo $mess;
            }

            $paramsArray['album_ids'] = $offer->category->vk_id;

            try {
                $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->addToAlbum($token, $paramsArray);
                });
            } catch (Exception $e) {
                $mess = "add to album for offer {$offer->id}: {$e->getMessage()}\n";
                $offer->vk_loading_error = $mess;
                Log::critical($mess);
                echo $mess;
            }

            $offer->save();
            echo "end to edit offer {$offer->id}\n";
        }
        echo "end to edit offers\n";
    }

    private function deletePhotos()
    {
        $token = $this->token;

        foreach ($this->getPhotosForDelete() as $photo) {
            $paramsArray = [
                'owner_id' => '-' . $this->group,
                'photo_id' => $photo->vk_id
            ];

            try {
                $response = $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->photos()->delete($token, $paramsArray);
                });

                if (!$response) {
                    continue;
                }

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
        echo "start to delete categories\n";

        foreach ($this->getAvailableCategoriesForSynchronize('deleted') as $category) {
            echo "start to delete category {$category->id}\n";

            if (!$category->vk_id) {
                continue;
            }
            try {
                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'album_id' => $category->vk_id,
                ];

                $response = $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->deleteAlbum($token, $paramsArray);
                });

                $category->markAsSynchronized();
            } catch (Exception $e) {
                $mess = "delete category {$category->shop_id}: {$e->getMessage()}\n";
                $category->vk_loading_error = $mess;
                Log::critical($mess);
                echo $mess;
            }

            $category->save();
            echo "end to delete category {$category->id}\n";
        }

        Category::where('status', 'deleted')
            ->where('synchronized', true)
            ->update(['can_load_to_vk' => 'no']);

        echo "end to delete category\n";
    }

    private function processDeletedOffers()
    {
        $token = $this->token;

        echo "start to delete offers\n";

        foreach ($this->getAvailableOffersForSynchronize('deleted') as $offer) {
            echo "start to delete offer {$offer->id}\n";
            if (!$offer->vk_id) {
                continue;
            }
            try {
                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'item_id' => $offer->vk_id,
                ];

                $response = $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->delete($token, $paramsArray);
                });

                $offer->markAsSynchronized();
            } catch (Exception $e) {
                $mess = "delete offer {$offer->id}: {$e->getMessage()}\n";
                $offer->vk_loading_error = $mess;
                Log::critical($mess);
                echo $mess;
            }

            $offer->save();
            echo "end to delete offer {$offer->id}\n";
        }

        echo "end to delete offers\n";
    }
}
