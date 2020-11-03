<?php

namespace App\Http\Controllers;

use App\Album;
use App\Traits\LaunchableTrait;
use Exception;
use Illuminate\Http\Request;
use VK\Client\VKApiClient;
use App\Settings;
use Illuminate\Support\Facades\Log;
use App\Task;
use App\Jobs\AlbumsDeletion;
use App\Jobs\ClearAllAlbums;

class AlbumController extends Controller
{
    use LaunchableTrait;

    protected $token;

    public function __construct()
    {
        $canLoadToVK = $this->checkAbilityOfLoading();
        if (!$canLoadToVK) {
            throw new Exception('Could not work with vk, please check token');
        }
    }

    public function index()
    {
        return view('album.index');
    }

    public function getAlbums($page)
    {
        $perPage = 100;

        $offset = ($page - 1) * $perPage;
        $group = Settings::where('name', 'group')->first();

        try {
            $paramsArray = [
                'offset' => $offset,
                'owner_id' => '-' . $group->value,
                'count' => $perPage
            ];
            $response = (new VKApiClient())->market()->getAlbums($this->token, $paramsArray);
            foreach ($response['items'] as &$item) {
                $albumItem = Album::where('album_id', $item['id'])->first();
                if ($albumItem) {
                    if (!$albumItem->is_done) {
                        $item['in_process'] = '+';
                    }
                } else {
                    $item['in_process'] = '';
                }
            }
        } catch (Exception $e) {
            Log::critical("Can't load album list display to frontend. {$e->getMessage()}");

            return;
        }

        return response()->json($response);
    }

    public function setTask(Request $request)
    {
        $selection = $request->post('selection');

        $task = new Task();
        $task->mode = $request->post('mode');
        $task->save();

        foreach ($selection as $album_id) {
            if ($task->mode == 'hard') {
                $resultItem = Album::where('album_id', $album_id)
                    ->where('is_done', true)->first();
                if ($resultItem && $resultItem->task->mode == 'hard') {
                    Log::warning("Can't set task for album {$album_id}. It is in another task");
                    continue;
                }
            }

            $album = new Album();
            $album->task_id = $task->id;
            $album->album_id = $album_id;
            $album->save();
        }

        if ($task->albums->count()) {
            dispatch(new AlbumsDeletion($task->id));
            return response()->json(['created' => 'yes']);
        }
    }

    public function setDeleteAllJob()
    {
        dispatch(new ClearAllAlbums());
    }
}
