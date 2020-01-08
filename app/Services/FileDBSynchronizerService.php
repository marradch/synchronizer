<?php

namespace App\Services;

use App\Traits\RetryTrait;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use Exception;
use App\Category;
use App\Offer;
use App\Picture;
use Illuminate\Support\Facades\Log;
use DB;

class FileDBSynchronizerService
{
    use RetryTrait;

    /** @var DOMDocument $dom */
    private $dom;

    public function processFile()
    {
        $fileName = $this->downloadAndExtract();
        echo "Start import file {$fileName}\n";
        $this->initiateDOM($fileName);
        $this->processCategoriesNodes();
        $this->processOffersNodes();
        $this->processAggregateProducts();
        echo "End import file {$fileName}\n";
    }

    private function downloadAndExtract()
    {
        $url = env('SHOP_IMPORT_FILE_URL', null);
        if (!$url) {
            throw new Exception('Please setup SHOP_IMPORT_FILE_URL to env');
        }
        echo "Start download file from {$url}\n";
        $this->downloadFile($url, true);
        $fileName = basename($url);
        echo "Start extract file from {$url}\n";
        $this->extractFile($fileName);

        return str_replace('.gz', '', $fileName);
    }

    private function extractFile($file_base_name)
    {
        $file_name = public_path() . '/downloads/' . basename($file_base_name);

        $buffer_size = 4096; // read 4kb at a time
        $out_file_name = str_replace('.gz', '', $file_name);

        $file = gzopen($file_name, 'rb');
        $out_file = fopen($out_file_name, 'wb');

        while (!gzeof($file)) {
            fwrite($out_file, gzread($file, $buffer_size));
        }

        fclose($out_file);
        gzclose($file);
    }

    private function initiateDOM($fileName)
    {
        $filePath = public_path() . '/downloads/' . $fileName;
        if (!file_exists($filePath)) {
            throw new Exception("Can\'t find extracted file $filePath");
        }
        $this->dom = new DOMDocument();
        $this->dom->preserveWhiteSpace = false;
        $result = $this->dom->load($filePath);
        if (!$result) {
            throw new Exception("Can\'t load $filePath in DOM");
        }
    }

    public function processCategoriesNodes()
    {
        $categories = $this->dom->getElementsByTagName('category');

        Category::where('status', '<>', 'deleted')
            ->update(['delete_sign' => true]);
        $counter = 0;
        foreach ($categories as $categoryNode) {
            $counter++;
            $shop_id = $categoryNode->getAttribute('id');

            $category = Category::where('shop_id', $shop_id)->first();

            if ($category) {
                if ($category->can_load_to_vk == 'yes') {
                    $category->turnDeletedStatus();
                }
                $this->editCategory($category, $categoryNode);
            } else {
                $this->addCategory($categoryNode);
            }

            echo "Processed {$counter} category\n";
        }

        $deletedCategories = Category::where('status', '<>', 'deleted')
            ->where('delete_sign', true)->get();

        foreach ($deletedCategories as $category) {
            $category->setStatus('deleted');
            $category->can_load_to_vk = 'no';
            $category->save();
        }

        $this->postLoadingCategoriesProcess();
    }

    public function processOffersNodes()
    {
        /** @var DOMNodeList $offers */
        $offers = $this->dom->getElementsByTagName('offer');
        Offer::where('status', '<>', 'deleted')
            ->where('is_aggregate', false)
            ->update(['delete_sign' => true]);
        $counter = 0;
        /** @var DOMNode $offerNode */
        foreach ($offers as $offerNode) {
            $counter++;

            if (!$this->validateOfferNode($offerNode)) {
                continue;
            }

            $vendorCode = $offerNode->getElementsByTagName('vendorCode')[0]->nodeValue;
            $offer = Offer::where('vendor_code', $vendorCode)
                ->where('is_aggregate', false)->first();

            if ($offer) {
                $newShopCategoryId = $offerNode->getElementsByTagName('categoryId')[0]->nodeValue;
                $newShopCategory = Category::where('shop_id', $newShopCategoryId)->first();

                if ($offer->category->can_load_to_vk != 'yes'
                    && $newShopCategory->can_load_to_vk != 'yes') {
                    $offer->delete_sign = false;
                    $offer->save();
                } else if ($offer->category->can_load_to_vk == 'yes'
                    && $newShopCategory->can_load_to_vk != 'yes') {
                    $offer->shop_category_id = $newShopCategoryId;
                    $offer->delete_sign = true;
                    $offer->check_sum = $this->buildOfferCheckSum($offerNode);
                    $offer->save();
                    // не сбиваем пометку удаления
                    // не актуализируем данные
                } else {
                    $offer->turnDeletedStatus();
                    $this->editOffer($offer, $offerNode);
                }
            } else {
                $this->addOffer($offerNode);
            }
            echo "Processed {$counter} offer\n";
        }
        $deletedOffers = Offer::where('delete_sign', true)->get();
        foreach ($deletedOffers as $offer) {
            $offer->setStatus('deleted');
            $offer->save();
        }
    }

    private function validateOfferNode($offerNode)
    {
        $fullNodesSetPresent = true;

        if (!isset($offerNode->getElementsByTagName('vendorCode')[0])) {
            $fullNodesSetPresent = false;
        }

        if (!isset($offerNode->getElementsByTagName('categoryId')[0])) {
            $fullNodesSetPresent = false;
        }

        if (!isset($offerNode->getElementsByTagName('price')[0])) {
            $fullNodesSetPresent = false;
        }

        if (!isset($offerNode->getElementsByTagName('name')[0])) {
            $fullNodesSetPresent = false;
        }

        if (!$fullNodesSetPresent) {
            $id = empty($offerNode->getAttribute('id')) ? 'not known' : $offerNode->getAttribute('id');
            Log::warning("node with id hasn't required nodes ($id)");
        }

        return $fullNodesSetPresent;
    }

    private function processAggregateProducts()
    {
        $this->addAggregateProducts();
        $this->modifyAggregateProducts();
    }

    private function modifyAggregateProducts()
    {
        echo "start to modify aggregate products\n";

        foreach ($this->getAggregatesCursor() as $aggregate) {

            $currentSizes = [];
            $currentParticipants = [];
            $isEditionNeed = false;

            $participants = Offer::where('vendor_code', 'like', "{$aggregate->vendor_code}%")
                ->where('id', '<>', $aggregate->vendor_code)
                ->where('is_aggregate', 0)
                ->orderBy('vendor_code')
                ->get();

            foreach ($participants as $participant) {
                if ($participant->status != 'deleted') {
                    $paramsArray = unserialize($participant->params);

                    if (empty($paramsArray['Размер'])) continue;

                    $currentSizes[] = $paramsArray['Размер'];
                    $currentParticipants[] = $participant->id;
                    $isEditionNeed = (!$participant->synch_with_aggregate) ? true : $isEditionNeed;
                }
            }

            if(count($participants)){
                $this->modifyAggregate($participants[0], $currentSizes, $currentParticipants, $isEditionNeed, $aggregate);
            }
        }

        echo "end to modify aggregate products\n";
    }

    private function getAggregatesCursor()
    {
        $offers = Offer::where('is_aggregate', true);

        foreach ($offers->cursor() as $offer) {
            yield $offer;
        }
    }

    private function modifyAggregate($baseItem, $currentSizes, $currentParticipants, $isEditionNeed, $aggregate)
    {
        echo "Try to modify aggregate {$baseItem->aggr_id}\n";

        // принятие решения о необходимости обновить/удалить агрегат
        if (!count($currentParticipants)) {
            if ($aggregate->status != 'deleted') {
                $aggregate->setStatus('deleted');
                $aggregate->save();
            }
        } else {
            if ($aggregate->status == 'deleted'
                && $aggregate->synchronized == true) {
                $aggregate->vk_id = 0;
                $aggregate->save();
                Picture::where('offer_id', $aggregate->id)->update([
                    'vk_id' => 0,
                    'status' => 'added',
                    'synchronized' => false,
                ]);
                $isEditionNeed = true;
            }
            sort($currentParticipants);
            $newCheckSum = md5(serialize($currentParticipants));
            // обновляем агрегат, если изменилось количество его участников
            // или кто-то из его предшественников был отредактирован
            $isEditionNeed = ($newCheckSum != $aggregate->check_sum) ? true : $isEditionNeed;
            if ($isEditionNeed) {
                $this->fillAggregate($baseItem, $currentSizes, $currentParticipants, $aggregate);
            }
        }
    }

    private function addAggregateProducts()
    {
        echo "start to add aggregate products\n";

        $resultArray = DB::table('offers as of1')
            ->select('of1.*', 'of2.id as add_id', 'of2.params as add_params', 'of2.vendor_code as add_vendor_code')
            ->join('offers as of2', function ($join) {
                $join->on('of2.vendor_code', 'like', DB::raw('concat(of1.vendor_code, \'%\')'));
                $join->on('of1.id', '<>', 'of2.id');
                $join->where('of1.is_excluded', 0);
                $join->where('of1.is_aggregate', 0);
                $join->where('of2.is_excluded', 0);
                $join->where('of2.is_aggregate', 0);
            })
            ->orderBy('vendor_code')
            ->get();

        $resultItemPrev = null;
        $currentSizes = [];
        $currentParticipants = [];
        $skip = false;

        foreach ($resultArray as $resultItem) {
            if ($resultItemPrev && $resultItem->id != $resultItemPrev->id) {

                if (!$skip) {
                    // формирование нового агрегата на основе циклично подготовленных данных
                    $this->fillAggregate($resultItemPrev, $currentSizes, $currentParticipants);
                }

                // обнуление подготовочных данных
                $currentSizes = [];
                $currentParticipants = [];
                $skip = false;
            }

            // инициализирем новые данные подготовительные данные для следующего агрегата
            // на основе первого оригинального айтема
            if (!count($currentSizes)) {
                $paramsArray = unserialize($resultItem->params);
                if (isset($paramsArray['Размер'])) {
                    $currentSizes[] = $paramsArray['Размер'];
                    $currentParticipants[] = $resultItem->id;
                } else {
                    $skip = true;
                    echo $resultItem->id . PHP_EOL;
                    print_r($paramsArray);
                }
            }
			
			;

			if (strripos('0123456789', $resultItem->add_vedor_code[strlen($resultItem->vedor_code)]) !== false) {
				$skip = true;
			}

            if (!$skip) {
                // записываем в подготовительные данные все присоединенные результаты
                $paramsArray = unserialize($resultItem->add_params);
                if (!empty($paramsArray['Размер'])) {
                    $currentSizes[] = $paramsArray['Размер'];
                }
                $currentParticipants[] = $resultItem->add_id;
            }

            $resultItemPrev = $resultItem;
        }
        if (!$skip && $resultItemPrev) {
            $this->fillAggregate($resultItemPrev, $currentSizes, $currentParticipants);
        }
        echo "end to add aggregate products\n";
    }

    private function fillAggregate($baseItem, $currentSizes, $currentParticipants, $aggregate = null)
    {
        echo "process aggregate for {$baseItem->id}\n";

        // формирование нового агрегата на основе циклично подготовленных данных
        if (!$aggregate) {
            $offer = new Offer();
            $status = 'added';
        } else {
            $offer = $aggregate;
            $status = 'edited';
        }
        $offer->shop_id = 0;
        $offer->shop_category_id = $baseItem->shop_category_id;
		$offer->shop_old_category_id = $baseItem->shop_old_category_id;
        $offer->name = $baseItem->name;
        $offer->price = $baseItem->price;
        if (!$aggregate) {
            $offer->vendor_code = $baseItem->vendor_code;
        }
        $offer->origin_description = $baseItem->origin_description;
        $offer->setStatus($status);
        $offer->is_aggregate = true;

        $params = unserialize($baseItem->params);
        if (intval($currentSizes[0])) {
            sort($currentSizes);
        } else {
            usort($currentSizes, 'App\Services\FileDBSynchronizerService::sortSizes');
        }

        if (empty($paramsArray['Размер'])) {
            unset($params['Размер']);
        }
        $params['Размеры'] = implode(', ', $currentSizes);
        $offer->params = serialize($params);

        $paramsText = '';
        $fullDescription = '';
        foreach ($params as $name => $value) {
            $paramsText .= $name . ': ' . $value . PHP_EOL;
        }
        $fullDescription .= $paramsText;
        $fullDescription .= PHP_EOL . $offer->origin_description . PHP_EOL;
        $fullDescription .= PHP_EOL . 'Пожалуйста, поделитесь ссылкой с друзьями';
        $offer->description = $fullDescription;
        sort($currentParticipants);
        $offer->check_sum = md5(serialize($currentParticipants));

        $offer->save();

        if ($aggregate) {
            $originOffer = Offer::find($baseItem->id);
            foreach ($originOffer->pictures as $picture) {
                if ($picture->status == 'added') {
                    $existedPicture = Picture::where('offer_id', $aggregate->id)
                        ->where('url', $picture->url)->first();
                    if (!$existedPicture) {
                        $newPicture = $picture->replicate();
                        $newPicture->offer_id = $offer->id;
                        $newPicture->save();
                    }
                } else {
                    if ($picture->status == 'deleted') {
                        $existedPicture = Picture::where('offer_id', $aggregate->id)
                            ->where('url', $picture->url)->first();
                        $existedPicture->setStatus('deleted');
                        $existedPicture->save();
                    }
                }
            }
        } else {
            $originOffer = Offer::find($baseItem->id);
            foreach ($originOffer->pictures as $picture) {
                $newPicture = $picture->replicate();
                $newPicture->offer_id = $offer->id;
                $newPicture->save();
            }
        }

        // айтемы, которые вошли в основу агрегата ,
        // не должны участвовать в загрузке в контакт
        Offer::whereIn('id', $currentParticipants)->update([
            'is_excluded' => true,
            'synch_with_aggregate' => true,
        ]);
    }

    private static function sortSizes($a, $b)
    {
        $sizes = ['S', 'S-M', 'M', 'M-L', 'L'];
        $idxA = array_search($a, $sizes);
        $idxB = array_search($b, $sizes);
        return ($idxA < $idxB) ? -1 : 1;
    }

    /**
     * @param DOMNode $offerNode
     */
    private function addOffer(DOMNode $offerNode)
    {
        $price = (int)$offerNode->getElementsByTagName('price')[0]->nodeValue;
        if ($price == 0) {
            return;
        }

        $offer = new Offer();

        $this->fillOfferFromNode($offer, $offerNode);

        $offer->setStatus('added');
        $offer->save();

        $pictures = $offerNode->getElementsByTagName('picture');
        foreach ($pictures as $pictureNode) {
            $this->addPicture($offer->id, $pictureNode);
        }
    }

    private function editOffer($offer, $offerNode)
    {
        $currentCheckSum = $this->buildOfferCheckSum($offerNode);

        if ($currentCheckSum == $offer->check_sum) {
            $offer->delete_sign = false;
            $offer->save();
        } else {
            $this->fillOfferFromNode($offer, $offerNode);
            $offer->setStatus('edited');
            $offer->synch_with_aggregate = false;
            $offer->save();

            $this->actualizePictures($offer, $offerNode);
        }
    }

    private function buildOfferCheckSum($offerNode)
    {
        $checkSumArray = [];
        $checkSumArray[] = $offerNode->getElementsByTagName('categoryId')[0]->nodeValue;
        $checkSumArray[] = $offerNode->getElementsByTagName('name')[0]->nodeValue;
        $checkSumArray[] = $offerNode->getElementsByTagName('price')[0]->nodeValue;
        $checkSumArray[] = $offerNode->getElementsByTagName('name')[0]->nodeValue;
        $params = $offerNode->getElementsByTagName('param');
        foreach ($params as $paramNode) {
            $checkSumArray[] = $paramNode->getAttribute('name');
            $checkSumArray[] = $paramNode->nodeValue;
        }
        $description = isset($offerNode->getElementsByTagName('description')[0])
                     ? $offerNode->getElementsByTagName('description')[0]->nodeValue : '';

        $checkSumArray[] = $description;
        $pictures = $offerNode->getElementsByTagName('picture');
        foreach ($pictures as $picture) {
            $checkSumArray[] = $picture->nodeValue;
        }

        return md5(serialize($checkSumArray));
    }

    private function fillOfferFromNode($offer, $offerNode)
    {
        $offer->shop_id = $offerNode->getAttribute('id');
        $offer->shop_old_category_id = $offer->shop_category_id ? $offer->shop_category_id : 0;
        $offer->shop_category_id = $offerNode->getElementsByTagName('categoryId')[0]->nodeValue;
        $offer->name = $offerNode->getElementsByTagName('name')[0]->nodeValue;
        $offer->price = $offerNode->getElementsByTagName('price')[0]->nodeValue;
        $offer->vendor_code = $offerNode->getElementsByTagName('vendorCode')[0]->nodeValue;

        $fullDescription = '';
        $fullDescription .= 'Артикул: ' . $offer->vendor_code . PHP_EOL;

        $params = $offerNode->getElementsByTagName('param');
        $paramsText = '';
        $paramsDescription = '';
        $paramsArray = [];

        foreach ($params as $paramNode) {
            if ($paramNode->getAttribute('name') == 'Описание') {
                $paramsDescription = $paramNode->nodeValue;
            } else {
                $paramsText .= $paramNode->getAttribute('name') . ': ' . $paramNode->nodeValue . PHP_EOL;
                $paramsArray[$paramNode->getAttribute('name')] = $paramNode->nodeValue;
            }
        }

        $fullDescription .= $paramsText;
        $offer->params = serialize($paramsArray);

        if (isset($offerNode->getElementsByTagName('description')[0])) {
            $nodeDescription = trim($offerNode->getElementsByTagName('description')[0]->nodeValue);
            $fullDescription .= PHP_EOL . $nodeDescription . PHP_EOL;
            $offer->origin_description = $nodeDescription;
        } else {
            if ($paramsDescription) {
                $fullDescription .= PHP_EOL . $paramsDescription . PHP_EOL;
                $offer->origin_description = $paramsDescription;
            }
        }

        $fullDescription .= PHP_EOL . 'Пожалуйста, поделитесь ссылкой с друзьями';

        $offer->description = $fullDescription;

        $offer->check_sum = $this->buildOfferCheckSum($offerNode);
    }

    private function actualizePictures($offer, $offerNode)
    {
        $picturesNodes = $offerNode->getElementsByTagName('picture');
        Picture::where('offer_id', $offer->id)
            ->where('status', '<>', 'deleted')
            ->update(['delete_sign' => true]);

        foreach ($picturesNodes as $pictureNode) {
            $actualUrls[] = trim($pictureNode->nodeValue);

            $picture = $offer->pictures
                ->where('url', '=', $pictureNode->nodeValue)
                ->where('status', '<>', 'deleted')
                ->first();

            if (!$picture) {
                $this->addPicture($offer->id, $pictureNode);
            } else {
                $picture->delete_sign = false;
                $picture->save();
            }
        }

        $deletedPictures = Picture::where('offer_id', $offer->id)
            ->where('status', '<>', 'deleted')
            ->where(['delete_sign' => true])
            ->get();

        foreach ($deletedPictures as $picture) {
            $picture->setStatus('deleted');
            $picture->save();
        }
    }

    private function addPicture($offerId, $pictureNode)
    {
        $picture = new Picture();
        $picture->offer_id = $offerId;
        $picture->url = trim($pictureNode->nodeValue);
        $picture->setStatus('added');
        $picture->save();

        $this->downloadFile($pictureNode->nodeValue);
    }

    private function downloadFile($url, $force = false)
    {
        $uploadPath = public_path() . '/downloads/';

        if (!file_exists($uploadPath)) {
            mkdir($uploadPath);
        }

        $path = $uploadPath . basename($url);
        if (!$force && file_exists($path)) {
            return;
        }

        try {
            $this->retry(function () use ($url, $path) {
                $this->internalDownloadFile($url, $path);
            }, 5, 10);
        } catch (Exception $e) {
            Log::critical("File upload error ({$url}): " . $e->getMessage());
        }
    }

    /**
     * @param $url
     * @param string $path
     * @throws Exception
     */
    private function internalDownloadFile($url, string $path): void
    {
        $fp = fopen($path, 'w+');
        $ch = curl_init(str_replace(" ", "%20", $url));
        curl_setopt($ch, CURLOPT_TIMEOUT, 50);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        $curl_error_code = curl_errno($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($curl_error || $curl_error_code) {
            $error_msg = "Failed curl request. Curl error {$curl_error_code}";
            if ($curl_error) {
                $error_msg .= ": {$curl_error}";
            }
            $error_msg .= '.';
            throw new Exception($error_msg);
        }
    }

    private function postLoadingCategoriesProcess()
    {
        $categories = Category::all();
        foreach ($categories as $category) {

            if ($category->status == 'deleted' || $category->synchronized) {
                continue;
            }

            $category->prepareName();
            $category->save();
        }
    }

    private function addCategory($categoryNode)
    {
        $category = new Category();
        $category->shop_id = $categoryNode->getAttribute('id');
        $parentId = $categoryNode->getAttribute('parentId');
        if ($parentId) {
            $category->shop_parent_id = $categoryNode->getAttribute('parentId');
        }
        $category->name = $categoryNode->nodeValue;
        $category->check_sum = md5($category->shop_parent_id
            . $category->name);
        $category->setStatus('added');
        $category->save();
    }

    private function editCategory($category, $categoryNode)
    {
        $new_check_sum = md5($categoryNode->getAttribute('parentId')
            . $categoryNode->nodeValue);
        if ($category->check_sum != $new_check_sum) {
            $category->name = $categoryNode->nodeValue;
            $parentId = $categoryNode->getAttribute('parentId');
            if ($parentId) {
                $category->shop_parent_id = $categoryNode->getAttribute('parentId');
            } else {
                $category->shop_parent_id = 0;
            }
            $category->check_sum = $new_check_sum;

            $category->setStatus('edited');
        } else {
            $category->delete_sign = false;
        }
        $category->save();
    }
}
