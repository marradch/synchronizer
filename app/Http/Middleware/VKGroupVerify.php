<?php

namespace App\Http\Middleware;

use Closure;
use App\Settings;

class VKGroupVerify
{
    public function handle($request, Closure $next)
    {
        $group = Settings::where('name', 'group')->first();
        if(!$group) {
            return redirect()->route('home');
        }

        return $next($request);
    }
}
