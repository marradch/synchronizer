<?php

namespace App\Services;

use App\Traits\Loggable;
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
    use Loggable;

    protected $group;
    protected $token;
    protected $VKApiClient;
    /**
     * @var false|mixed
     */
    private $fixCategoriesPictures;

    public function __construct($fixCategories = false)
    {
        $this->VKApiClient = new VKApiClient();
        $this->fixCategoriesPictures = $fixCategories;
    }

    public function loadAllToVK()
    {
        $canLoadToVK = $this->checkAbilityOfLoading();
        if (!$canLoadToVK) {
            return;
        }

        echo "start loading to VK\n";

        if ($this->fixCategoriesPictures) {
            $this->fixCategoriesPictures();
        }

        $this->loadAddedCategoryToVK();
        $this->loadAddedOffersToVK();

        $this->processEditedCategories();
        $this->processEditedOffers();

        $this->processDeletedCategories();
        $this->processDeletedOffers();

        $this->fillGapsInOverflowAlbums();

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

                $response = $this->retry(
                    function () use ($token, $paramsArray) {
                        return $this->VKApiClient->market()->addAlbum($token, $paramsArray);
                    }
                );
                $this->log("loadAddedCategoryToVK addAlbum:", $response);
                $category->update(['vk_id' => $response['market_album_id']]);

                $result = $this->addPictureToCategory($category);
                $this->log("addAlbum addPictureToCategory:", $result);
                $category->markAsSynchronized($response['market_album_id']);
            } catch (Throwable $e) {
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

    private function loadPictureToVK($picture, $ind, $hasMain, $duplicate = false)
    {
        echo "start load picture {$picture->id}\n";

        $token = $this->token;
        try {
            $local_path = $picture->local_path;
            $paramsArray = [
                'group_id' => $this->group
            ];
            $photoType = 'usual photo';
            if (!$hasMain && ($ind == 0)) {
                $paramsArray['main_photo'] = 1;
                $photoType = 'main photo';
            }
            $result = $this->retry(
                function () use ($token, $paramsArray) {
                    return $this->VKApiClient->photos()->getMarketUploadServer($token, $paramsArray);
                }
            );
            $this->log("loadPictureToVK getMarketUploadServer for {$photoType}:", $result);
        } catch (Exception $e) {
            $mess = 'error in getMarketUploadServer: ' . $e->getMessage() . "\n";
            $picture->vk_loading_error = $mess;
            $picture->save();
            Log::critical($mess);
            echo $mess;

            return false;
        }

        $uploadUrl = $result['upload_url'];

        try {
            $resultArray = $this->retry(
                function () use ($uploadUrl, $local_path) {
                    return $this->VKApiClient->getRequest()->upload($uploadUrl, 'photo', $local_path);
                }
            );
            $this->log("loadPictureToVK upload for {$photoType}:", $resultArray);
        } catch (Throwable $e) {
            $mess = "Picture {$picture->url}($picture->id) wasn't uploaded: {$e->getMessage()}\n";
            $this->log($mess);
            $picture->vk_loading_error = $mess;
            $picture->save();
            Log::critical($mess);
            return false;
        }

        try {
            $resultArray['group_id'] = $this->group;
            $result = $this->retry(
                function () use ($token, $resultArray) {
                    return $this->VKApiClient->photos()->saveMarketPhoto($token, $resultArray);
                }
            );
            $this->log("loadPictureToVK saveMarketPhoto:", $result);
            echo "end load picture {$picture->id}\n";

            if (!$duplicate) {
                $picture->markAsSynchronized($result[0]['id']);
                if (!$hasMain && ($ind == 0)) {
                    $picture->is_main = 1;
                }
                $picture->save();

                return $picture->vk_id;
            } else {
                return $result[0]['id'];
            }
        } catch (Exception $e) {
            $mess = "error to load picture {$picture->id}: {$e->getMessage()}\n";
            $this->log($mess);
            $picture->vk_loading_error = $mess;
            $picture->save();
            Log::critical($mess);
            echo $mess;
            return false;
        }
    }

    private function getAvailableCategoriesForSynchronize($status)
    {
        $categories = Category::where('synchronized', false)
            ->where('status', $status);

        if ($status == 'added') {
            $categories->has('offers');
        } else {
            $categories->where('vk_id', '>', 0);
        }

        if ($status != 'deleted') {
            $categories->where('can_load_to_vk', 'yes');
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
            $this->log("pictureIds:", $picturesIds);
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
                $response = $this->retry(
                    function () use ($token, $paramsArray) {
                        return $this->VKApiClient->market()->add($token, $paramsArray);
                    }
                );
                $this->log("loadAddedOffersToVK add:", $response);
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
                $response = $this->retry(
                    function () use ($token, $paramsArray) {
                        return $this->VKApiClient->market()->addToAlbum($token, $paramsArray);
                    }
                );
                $this->log("loadAddedOffersToVK addToAlbum:", $response);
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

        $hasMain = Picture::where('offer_id', $offer->id)
                ->where('is_main', 1)
                ->where('synchronized', 1)
                ->where('status', 'added')
                ->count() > 0;

        foreach ($pictures as $ind => $picture) {
            $this->loadPictureToVK($picture, $ind, $hasMain);
        }
    }

    private function getAvailableOffersForSynchronize($status)
    {
        $offers = Offer::where('synchronized', false)
            ->where('is_excluded', false)
            ->orderBy('shop_category_id');

        if ($status != 'deleted') {
            $offers->whereHas(
                'category',
                function (Builder $query) {
                    $query->where('can_load_to_vk', 'yes');
                }
            );
        }

        if ($status == 'added') {
            $offers->whereRaw("(status = 'added' or (status = 'edited' and vk_id = 0))");
        } else {
            $offers->where('status', $status);
            $offers->where('vk_id', '>', 0);
        }

        foreach ($offers->cursor() as $offer) {
            yield $offer;
        }
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

                $response = $this->retry(
                    function () use ($token, $paramsArray) {
                        return $this->VKApiClient->market()->editAlbum($token, $paramsArray);
                    }
                );
                $this->log("processEditedCategories editAlbum:", $response);

                $result = $this->addPictureToCategory($category);
                $this->log("editAlbum addPictureToCategory:", $result);
                $category->markAsSynchronized();
            } catch (Throwable $e) {
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
                $response = $this->retry(
                    function () use ($token, $paramsArray) {
                        return $this->VKApiClient->market()->edit($token, $paramsArray);
                    }
                );
                $this->log("processEditedOffers edit:", $response);
            } catch (Exception $e) {
                $mess = "error to load offer {$offer->id}: {$e->getMessage()}\n";
                Log::critical($mess);
                $offer->vk_loading_error = $mess;
                echo $mess;
            }

            if (!($offer->shop_category_id == $offer->shop_old_category_id || !$offer->shop_old_category_id)) {
                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'item_id' => $offer->vk_id,
                ];

                if ($offer->oldcategory) {
                    $paramsArray['album_ids'] = $offer->oldcategory->vk_id;

                    try {
                        $response = $this->retry(
                            function () use ($token, $paramsArray) {
                                return $this->VKApiClient->market()->removeFromAlbum($token, $paramsArray);
                            }
                        );
                        $this->log("processEditedOffers removeFromAlbum:", $response);
                    } catch (Exception $e) {
                        $mess = "error to remove to album for offer {$offer->id}: {$e->getMessage()}\n";
                        Log::critical($mess);
                        $offer->vk_loading_error = $mess;
                        echo $mess;
                    }
                }

                $paramsArray['album_ids'] = $offer->category->vk_id;

                try {
                    $response = $this->retry(
                        function () use ($token, $paramsArray) {
                            return $this->VKApiClient->market()->addToAlbum($token, $paramsArray);
                        }
                    );
                    $this->log("processEditedOffers addToAlbum:", $response);
                } catch (Exception $e) {
                    $mess = "add to album for offer {$offer->id}: {$e->getMessage()}\n";
                    $offer->vk_loading_error = $mess;
                    Log::critical($mess);
                    echo $mess;
                }
            }

            $offer->markAsSynchronized();
            $offer->save();
            echo "end to edit offer {$offer->id}\n";
        }
        echo "end to edit offers\n";
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

                $response = $this->retry(
                    function () use ($token, $paramsArray) {
                        return $this->VKApiClient->market()->deleteAlbum($token, $paramsArray);
                    }
                );

                $this->log("processDeletedCategories deleteAlbum:", $response);
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

                $response = $this->retry(
                    function () use ($token, $paramsArray) {
                        return $this->VKApiClient->market()->delete($token, $paramsArray);
                    }
                );

                $this->log("processDeletedOffers delete:", $response);
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

    function fillGapsInOverflowAlbums()
    {
        echo "start to fill gaps in overflow albums\n";
        $token = $this->token;

        $haveMore = true;
        $offset = 0;

        while ($haveMore) {
            $paramsArray = [
                'owner_id' => '-' . $this->group,
                'album_id' => 0,
                'count' => 200,
                'extended' => 1,
                'offset' => $offset,
            ];

            try {
                $response = $this->retry(
                    function () use ($token, $paramsArray) {
                        return $this->VKApiClient->market()->get($token, $paramsArray);
                    }
                );
                $this->log("fillGapsInOverflowAlbums get:", $response);
            } catch (Exception $e) {
                $mes = 'can\'t get offers without album: ' . $e->getMessage();
                Log::critical($mes);
                echo $mes;

                $haveMore = false;
                continue;
            }

            $offset += 200;
            $haveMore = (($response['count'] - $offset) > 0) ? true : false;

            foreach ($response['items'] as $item) {
                echo "Process item {$item['id']}" . PHP_EOL;
                if (count($item['albums_ids'])) {
                    continue;
                }

                $offer = Offer::where('vk_id', $item['id'])->first();
                if (!$offer) {
                    continue;
                }

                if (!($offer->category
                    && $offer->category->vk_id
                    && $offer->category->status != 'deleted'
                    && $offer->category->synchronized)) {
                    continue;
                }

                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'album_ids' => $offer->category->vk_id,
                ];

                try {
                    $response = $this->retry(
                        function () use ($token, $paramsArray) {
                            return $this->VKApiClient->market()->getAlbumById($token, $paramsArray);
                        }
                    );
                    $this->log("fillGapsInOverflowAlbums getAlbumById:", $response);
                } catch (Exception $e) {
                    $mess = 'can\'t get data for album ' . $offer->category->vk_id . ': ' . $e->getMessage();
                    $offer->vk_loading_error .= $mess;
                    $offer->save();
                    Log::critical($mess);
                    echo $mess;

                    continue;
                }

                if (empty($response['count'])) {
                    continue;
                }

                if ($response['items'][0]['count'] >= 1000) {
                    continue;
                }

                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'item_id' => $offer->vk_id,
                    'album_ids' => $offer->category->vk_id
                ];

                try {
                    $response = $this->retry(
                        function () use ($token, $paramsArray) {
                            return $this->VKApiClient->market()->addToAlbum($token, $paramsArray);
                        }
                    );
                    $this->log("fillGapsInOverflowAlbums addToAlbum:", $response);
                    echo "success process item {$item['id']}" . PHP_EOL;
                } catch (Exception $e) {
                    $mess = "error add to album for offer {$offer->id}: {$e->getMessage()}\n";
                    $offer->vk_loading_error .= $mess;
                    $offer->save();
                    Log::critical($mess);
                    echo $mess;
                }
            }
        }

        echo "end to fill gaps in overflow albums\n";
    }

    private function fixCategoriesPictures()
    {
        $this->log("Fix categories pictures, start");
        $categoriesToFixQuery = Category::query()
            ->where('can_load_to_vk', 'yes')
            ->where('vk_id', '>', 0)
            ->where('picture_vk_id', '<=', 0)
            ->where('status', '<>', 'deleted');
        $countCategories = $categoriesToFixQuery->count();
        $this->log("Categories to fix: " . $countCategories);
        foreach ($categoriesToFixQuery->get() as $ind => $category) {
            $currentInd = $ind + 1;
            $this->log("{$currentInd}/{$countCategories} fix categories pictures for: " . $category->prepared_name);
            try {
                $result = $this->addPictureToCategory($category);
                $this->log("fixCategoriesPictures editAlbum: ", $result);
            } catch (Throwable $e) {
                $this->log("Error while fixCategoriesPictures: " . $e->getMessage());
            }
        }
        $this->log("Fix categories pictures, finish");
    }

    private function loadAlbumPictureToVK($picture, $groupId)
    {
        $params = [
            'group_id' => $groupId
        ];
        $token = $this->token;
        $result = $this->retry(
            function () use ($token, $params) {
                return $this->VKApiClient->photos()->getMarketAlbumUploadServer($token, $params);
            }
        );
        $this->log("getMarketAlbumUploadServer", $result);
        if (!isset($result['upload_url'])) {
            $error = "Could not get getMarketAlbumUploadServer, skip";
            $this->log($error);
            throw new Exception($error);
        }
        $uploadUrl = $result['upload_url'];
        $result = $this->retry(
            function () use ($uploadUrl, $picture) {
                return $this->VKApiClient->getRequest()->upload($uploadUrl, 'photo', $picture->local_path);
            }
        );
        $this->log("upload to $uploadUrl", $result);
        if (!isset($result['server'])) {
            $error = "Could not upload album picture, skip";
            $this->log($error);
            throw new Exception($error);
        }
        $params = [
            'group_id' => $groupId,
            'server' => $result['server'],
            'photo' => $result['photo'],
            'hash' => $result['hash']
        ];
        $token = $this->token;
        $result = $this->retry(
            function () use ($token, $params) {
                return $this->VKApiClient->photos()->saveMarketAlbumPhoto($token, $params);
            }
        );
        $this->log("saveMarketAlbumPhoto", $result);
        if (!isset($result[0]['id'])) {
            $error = "Could not saveMarketAlbumPhoto, skip";
            $this->log($error);
            throw new Exception($error);
        }

        return $result[0]['id'];
    }

    /**
     * @param Builder $category
     * @return mixed
     * @throws Throwable
     */
    private function addPictureToCategory(Category $category)
    {
        $pictureItem = $category->picture;
        $pictureVkId = $this->loadAlbumPictureToVK($pictureItem, $this->group);
        $category->update(['picture_vk_id' => $pictureVkId, 'vk_loading_error' => '']);
        $params = [
            'owner_id' => '-' . $this->group,
            'album_id' => $category->vk_id,
            'title' => $category->prepared_name,
            'photo_id' => $pictureVkId
        ];
        $this->log("editAlbum params", $params);
        $token = $this->token;
        $result = $this->retry(
            function () use ($token, $params) {
                return $this->VKApiClient->market()->editAlbum($token, $params);
            }
        );
        $this->log("editAlbum result", $result);
        return $result;
    }
}
