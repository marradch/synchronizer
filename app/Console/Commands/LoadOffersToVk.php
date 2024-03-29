<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DBVKSynchronizerService;
use Illuminated\Console\WithoutOverlapping;

class LoadOffersToVk extends Command
{
    use WithoutOverlapping;

    protected $mutexStrategy = 'file';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'synchronizer:load-offers-to-vk';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Load offers and categories to vk from database';

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
            (new DBVKSynchronizerService())->loadAllToVK();
        } catch (\Throwable $e) {
            Log::critical("Exception while importing file: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
