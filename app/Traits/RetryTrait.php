<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use VK\Exceptions\Api\VKApiCaptchaException;

trait RetryTrait
{
    function retry($f, $delay = 1, $retries = 5)
    {
        try {
            sleep(2);
            return $f();
        } catch (VKApiCaptchaException $e) {
            Log::warning('Service stop because vk need captcha');
            exit('Service stop because vk need captcha');
        } catch (\Throwable $e) {
            if ($retries > 0) {
                sleep($delay);
                return $this->retry($f, $delay, $retries - 1);
            } else {
                throw $e;
            }
        }
    }
}

