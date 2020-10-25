<?php

namespace App\Traits;

trait Loggable
{
    public function log($line, $payload = null)
    {
        if (!env('LOGGING')) {
            return false;
        }
        $payload = $payload ?
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
            : '';
        echo date('Y-m-d H:i:s')
            . " "
            . $line
            . PHP_EOL
            . $payload;
    }
}
