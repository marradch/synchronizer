<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Album extends Model
{
    protected $fillable = [
        'album_id',
        'task_id',
        'is_done',
    ];

    protected $table = 'vk_delete_album__albums';

    public function task()
    {
        return $this->belongsTo('App\Task', 'task_id', 'id');
    }
}
