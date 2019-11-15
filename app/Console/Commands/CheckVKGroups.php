<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use App\Services\DeletionService;
use Illuminate\Support\Facades\Log;
use Illuminated\Console\WithoutOverlapping;

class CheckVKGroups extends Command
{
    use WithoutOverlapping;

    protected $mutexStrategy = 'mysql';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'synchronizer:check-vk-groups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'check if vk groups are in database';

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
            (new DeletionService())->checkGroups();
        } catch (Exception $e) {
            Log::critical("Exception while importing file: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
