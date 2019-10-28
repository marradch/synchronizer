<?php

namespace App\Services;

use DOMDocument;
use DOMNode;
use DOMNodeList;
use Exception;
use App\Category;
use App\Offer;
use App\Picture;

class FileDBSynchronizerService
{
    /** @var DOMDocument $dom */
    private $dom;

    public function processFile()
    {
        $this->initiateDOM();
        $this->processCategoriesNodes();
        $this->processOffersNodes();
    }

    private function initiateDOM()
    {
        $filePath = env('SHOP_IMPORT_FILE_URL', null);
        if (!$filePath) {
            throw new Exception('Please setup SHOP_IMPORT_FILE_URL to env');
        }
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->load($filePath);
        $this->dom = $dom;
    }

    public function processCategoriesNodes()
    {
        $categories = $this->dom->getElementsByTagName('category');

        Category::where('status', '<>', 'deleted')
            ->update(['delete_sign' => true]);
        foreach ($categories AS $categoryNode) {
            $shop_id = $categoryNode->getAttribute('id');

            $category = Category::where('shop_id', $shop_id)->first();

            if ($category) {
                $this->editCategory($category, $categoryNode);
            } else {
                $this->addCategory($categoryNode);
            }
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
        /** @var DOMNode $offerNode */
        foreach ($offers as $offerNode) {
            $shop_id = $offerNode->getAttribute('id');

            $offer = Offer::where('shop_id', $shop_id)->first();

            if ($offer) {
                $this->editOffer($offer, $offerNode);
            } else {
                $this->addOffer($offerNode);
            }
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

    function retry($f, $delay = 10, $retries = 3)
    {
        try {
            return $f();
        } catch (Exception $e) {
            if ($retries > 0) {
                sleep($delay);
                return $this->retry($f, $delay, $retries - 1);
            } else {
                throw $e;
            }
        }
    }

    private function downloadFile($url)
    {
        $uploadPath = public_path() . '/downloads/';

        if(!file_exists($uploadPath)) {
            mkdir($uploadPath);
        }

        $path = $uploadPath . basename($url);
        $this->retry(function () use ($url, $path) {
            $this->internalDownloadFile($url, $path);
        });
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
