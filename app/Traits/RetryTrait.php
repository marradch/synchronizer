<?php

namespace App\Traits;

trait RetryTrait
{
    function retry($f, $delay = 1, $retries = 5)
    {
        try {
            return $f();
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

