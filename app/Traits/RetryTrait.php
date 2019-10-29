<?php

namespace App\Traits;

trait RetryTrait {
    function retry($f, $delay = 1, $retries = 3)
    {
        try {
            return $f();
            sleep($delay);
        } catch (Exception $e) {
            if ($retries > 0) {
                sleep($delay);
                return $this->retry($f, $delay, $retries - 1);
            } else {
                throw $e;
            }
        }
    }
}

