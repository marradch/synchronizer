<?php

namespace App\Traits;

trait Loggable
{
    public function log($line, $payload)
    {
        if (!env('LOGGING')) {
            return false;
        }
        echo date('Y-m-d H:i:s')
            . $line
            . PHP_EOL
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . PHP_EOL;
    }
}
