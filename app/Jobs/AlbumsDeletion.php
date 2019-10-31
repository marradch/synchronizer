<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\DeletionService;

class AlbumsDeletion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $taskId;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($taskId)
    {
        $this->taskId = $taskId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        (new DeletionService())->performTask($this->taskId);
    }
}
