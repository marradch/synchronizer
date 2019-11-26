<?php

namespace App\Services;

use App\Traits\RetryTrait;
use App\Traits\LaunchableTrait;
use Exception;
use VK\Client\VKApiClient;
use App\Task;
use App\Album;
use Illuminate\Support\Facades\Log;
use App\Category;

class DeletionService
{
    use RetryTrait;
    use LaunchableTrait;

    protected $group;
    protected $token;
    protected $VKApiClient;

    public function __construct()
    {
        $this->VKApiClient = new VKApiClient();
    }

    public function performTask($taskId)
    {
        $canLoadToVK = $this->checkAbilityOfLoading();
        if (!$canLoadToVK) {
            return;
        }

        $token = $this->token;
        $task = Task::find($taskId);

        $albums = Album::where('task_id', $taskId)
            ->where('is_done', false)->get();

        foreach ($albums as $album) {
            echo "Process album {$album->album_id} from task {$taskId}".PHP_EOL;

            $haveMore = true;
            $wasDeletedAll = true;

            while($haveMore) {

                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'album_id' => $album->album_id,
                    'count' => 200,
                ];

                try {
                    $response = $this->retry(function () use ($token, $paramsArray) {
                        return $this->VKApiClient->market()->get($token, $paramsArray);
                    });
                } catch (Exception $e) {
                    $album->vk_loading_error .= PHP_EOL . $e->getMessage();

                    $mes = 'can\'t delete all offers for album ' . $album->album_id . ': ' . $e->getMessage();
                    Log::critical($mes);
                    echo $mes;

                    $wasDeletedAll = false;
                    $haveMore = false;
                    continue;
                }

                $haveMore = (($response['count'] - count($response['items'])) > 0) ? true : false;

                foreach($response['items'] as $item) {
                    $paramsArray = [
                        'owner_id' => '-' . $this->group,
                        'item_id' => $item['id'],
                    ];

                    try {
                        $this->retry(function () use ($token, $paramsArray) {
                            return $this->VKApiClient->market()->delete($token, $paramsArray);
                        });
                    } catch (Exception $e) {
                        $album->vk_loading_error .= PHP_EOL . $e->getMessage();
                        $mes = 'can\'t delete all offers for album ' . $album->album_id . ': ' . $e->getMessage();
                        Log::critical($mes);
                        echo $mes;
                        $wasDeletedAll = false;
                    }
                }
            }

            if($task->mode == 'hard') {
                if($wasDeletedAll) {
                    try {
                        $paramsArray = [
                            'owner_id' => '-' . $this->group,
                            'album_id' => $album->album_id,
                        ];

                        $this->retry(function () use ($token, $paramsArray) {
                            return $this->VKApiClient->market()->deleteAlbum($token, $paramsArray);
                        });

                        $album->is_done = true;
                    } catch (Exception $e) {
                        $album->vk_loading_error .= PHP_EOL . $e->getMessage();
                        $mes = 'can\'t delete album ' . $album->album_id . ': ' . $e->getMessage();
                        Log::critical($mes);
                        echo $mes;
                    }
                }
            } else {
                $album->is_done = true;
            }

            $album->save();
        }
    }

    public function deleteAll()
    {
        $canLoadToVK = $this->checkAbilityOfLoading();
        if (!$canLoadToVK) {
            return;
        }

        $token = $this->token;

        $paramsArray = [
            'owner_id' => '-' . $this->group,
            'count' => 100,
        ];


        try {
            $response = $this->retry(function () use ($token, $paramsArray) {
                return $this->VKApiClient->market()->getAlbums($token, $paramsArray);
            });
        } catch (Exception $e) {
            $mes = 'can\'t get albums list : ' . $e->getMessage();
            Log::critical($mes);

            return;
        }

        foreach ($response['items'] as $album) {
            $this->deleteOffers($album['id']);
        }

        $this->deleteOffers();
    }

    private function deleteOffers($albumId = 0)
    {
        $token = $this->token;
        echo "Process album {$albumId}" . PHP_EOL;

        $haveMore = true;

        while ($haveMore) {

            $paramsArray = [
                'owner_id' => '-' . $this->group,
                'album_id' => $albumId,
                'count' => 200,
            ];

            try {
                $response = $this->retry(function () use ($token, $paramsArray) {
                    return $this->VKApiClient->market()->get($token, $paramsArray);
                });
            } catch (Exception $e) {
                $mes = 'can\'t delete all offers for album ' . $albumId . ': ' . $e->getMessage();
                Log::critical($mes);
                echo $mes;

                $haveMore = false;
                continue;
            }

            $haveMore = (($response['count'] - count($response['items'])) > 0) ? true : false;

            foreach ($response['items'] as $item) {
                $paramsArray = [
                    'owner_id' => '-' . $this->group,
                    'item_id' => $item['id'],
                ];

                try {
                    $this->retry(function () use ($token, $paramsArray) {
                        return $this->VKApiClient->market()->delete($token, $paramsArray);
                    });
                } catch (Exception $e) {
                    $mes = 'can\'t delete all offers for album ' . $albumId . ': ' . $e->getMessage();
                    Log::critical($mes);
                    echo $mes;
                }
            }
        }
    }

    public function checkGroups()
    {
        $canLoadToVK = $this->checkAbilityOfLoading();
        if (!$canLoadToVK) {
            return;
        }

        $token = $this->token;

        $paramsArray = [
            'owner_id' => '-' . $this->group,
            'count' => 100,
        ];


        try {
            $response = $this->retry(function () use ($token, $paramsArray) {
                return $this->VKApiClient->market()->getAlbums($token, $paramsArray);
            });
        } catch (Exception $e) {
            $mes = 'can\'t get albums list : ' . $e->getMessage();
            Log::critical($mes);

            return;
        }

        foreach ($response['items'] as $album) {
            echo "Process album {$album['id']}" . PHP_EOL;

            $existed = Category::where('vk_id', $album['id'])->first();
            if (!$existed) {
                echo "Album {$album['id']} - ({$album['title']}) absent in database\n";
            }
        }
    }
}
