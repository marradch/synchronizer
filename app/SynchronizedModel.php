<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

abstract class SynchronizedModel extends Model
{

    protected $fillable = [
        'delete_sign',
        'status',
        'status_date',
        'synchronized',
        'synchronize_date',
        'vk_id',
    ];

    public function markAsSynchronized($vk_id = false)
    {
        if($vk_id) {
            $this->vk_id = $vk_id;
        }
        $this->synchronized = true;
        $this->synchronize_date = date('Y-m-d H:i:s');
    }

    public function setStatus($status)
    {
        $this->status = $status;
        $this->status_date = date('Y-m-d H:i:s');
        $this->synchronized = false;
        $this->delete_sign = false;
    }

    public function turnDeletedStatus()
    {
        if ($this->status != 'deleted') return;

        $this->status = 'added';

        if ($this->synchronized) {
            $this->vk_id = 0;
            $this->synchronized = false;
        } else {
            if ($this->vk_id) {
                $this->synchronized = true;
            }
        }
        $this->save();
    }
}
