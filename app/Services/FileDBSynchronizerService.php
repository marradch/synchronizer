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

class FileDBSynchronizerService
{
    use RetryTrait;

    /** @var DOMDocument $dom */
    private $dom;

    public function processFile()
    {
        $fileName = $this->downloadAndExtract();
        $this->initiateDOM($fileName);
        $this->processCategoriesNodes();
        $this->processOffersNodes();
    }

    private function downloadAndExtract()
    {
        $url = env('SHOP_IMPORT_FILE_URL', null);
        if (!$url) {
            throw new Exception('Please setup SHOP_IMPORT_FILE_URL to env');
        }

        $this->downloadFile($url);
        $fileName = basename($url);
        $this->extractFile($fileName);

        return str_replace('.gz', '', $fileName);
    }

    private function extractFile($file_base_name)
    {
        $file_name = public_path().'/downloads/'.basename($file_base_name);

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
        $filePath = public_path().'/downloads/'.$fileName;
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
        foreach ($categories AS $categoryNode) {
            $counter++;
            $shop_id = $categoryNode->getAttribute('id');

            $category = Category::where('shop_id', $shop_id)->first();

            if ($category) {
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
            $category->save();
        }

        $this->postLoadingCategoriesProcess();
    }

    public function processOffersNodes()
    {
        /** @var DOMNodeList $offers */
        $offers = $this->dom->getElementsByTagName('offer');
        Offer::where('status', '<>', 'deleted')
            ->update(['delete_sign' => true]);
        $counter = 0;
        /** @var DOMNode $offerNode */
        foreach ($offers as $offerNode) {
            $counter++;
            $shop_id = $offerNode->getAttribute('id');

            $offer = Offer::where('shop_id', $shop_id)->first();

            if ($offer) {
                $this->editOffer($offer, $offerNode);
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
        foreach ($pictures AS $pictureNode) {
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
        foreach ($params AS $paramNode) {
            $checkSumArray[] = $paramNode->getAttribute('name');
            $checkSumArray[] = $paramNode->nodeValue;
        }
        $checkSumArray[] = $offerNode->getElementsByTagName('description')[0]->nodeValue;
        $pictures = $offerNode->getElementsByTagName('picture');
        foreach ($pictures AS $picture) {
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

        foreach ($params AS $paramNode) {
            if ($paramNode->getAttribute('name') == 'Описание') {
                $paramsDescription = $paramNode->nodeValue;
            } else {
                $paramsText .= $paramNode->getAttribute('name') . ': ' . $paramNode->nodeValue . PHP_EOL;
            }
        }

        $fullDescription .= $paramsText;

        if (isset($offerNode->getElementsByTagName('description')[0])) {
            $nodeDescription = trim($offerNode->getElementsByTagName('description')[0]->nodeValue);
            $fullDescription .= PHP_EOL . $nodeDescription . PHP_EOL;
        } else {
            if ($paramsDescription) {
                $fullDescription .= PHP_EOL . $paramsDescription . PHP_EOL;
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

        foreach ($picturesNodes AS $pictureNode) {
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

    private function downloadFile($url)
    {
        $uploadPath = public_path() . '/downloads/';

        if(!file_exists($uploadPath)) {
            mkdir($uploadPath);
        }

        $path = $uploadPath . basename($url);
        if (file_exists($path)) {
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
