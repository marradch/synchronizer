<?php

namespace App\Traits;

use App\Services\VKAuthService;
use App\Settings;
use App\Token;
use Illuminate\Support\Facades\Log;

trait LaunchableTrait
{
    protected function checkAbilityOfLoading()
    {
        $isTokenSet = $this->setToken();
        if (!$isTokenSet) {
            Log::critical('Токен либо не установлен. Либо не действительный');
            return false;
        }

        $isGroupSet = $this->setGroup();
        if (!$isGroupSet) {
            Log::critical('Нет установленной группы для загрузки фотографий');
            return false;
        }

        return true;
    }

    protected function setGroup()
    {
        $group = Settings::where('name', 'group')->first();
        if ($group) {
            $this->group = $group->value;
            return true;
        } else {
            return false;
        }
    }

    protected function setToken()
    {
        $hasToken = (new VKAuthService())->checkOfflineToken();
        sleep(1);
        if ($hasToken) {
            $this->token = Token::first()->token;
            return true;
        }
        return false;
    }
}
