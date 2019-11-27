<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use App\Services\FileDBSynchronizerService;
use App\Services\DBVKSynchronizerService;
use Illuminate\Support\Facades\Log;
use Illuminated\Console\WithoutOverlapping;

class TwoSyncSteps extends Command
{
    use WithoutOverlapping;

    protected $mutexStrategy = 'file';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:two-sync-steps';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'load data from file to db and load data from db to vk';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            (new FileDBSynchronizerService())->processFile();
        } catch (Exception $e) {
            Log::critical("Exception while load from file to db: {$e->getMessage()}");
            Log::critical(print_r($e->getTrace(), true));
            return 1;
        }

        try {
            (new DBVKSynchronizerService())->loadAllToVK();
        } catch (Exception $e) {
            Log::critical("Exception while load from db to vk: {$e->getMessage()}");
            Log::critical(print_r($e->getTrace(), true));
            return 1;
        }

        return 0;
    }
}
