<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = [
        'mode',
    ];

    protected $table = 'vk_delete_album__tasks';

    public function albums()
    {
        return $this->hasMany('App\Album', 'task_id', 'id');
    }
}
