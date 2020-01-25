<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use App\Services\DeletionService;
use Illuminate\Support\Facades\Log;
use Illuminated\Console\WithoutOverlapping;

class DeleteOffersFromWrongCategories extends Command
{
    use WithoutOverlapping;

    protected $mutexStrategy = 'file';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'synchronizer:delete-wrong-offers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'delete offers from wrong album';

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
            (new DeletionService())->removeOffersFromDisabledCategories();
        } catch (Exception $e) {
            Log::critical("Exception while delete all offers: {$e->getMessage()}");
            var_dump($e->getTrace());
            return 1;
        }

        return 0;
    }
}
