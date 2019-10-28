<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use App\Services\FileDBSynchronizerService;
use Illuminate\Support\Facades\Log;

class LoadFromFileToDb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'synchronizer:load-from-file-to-db';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'load categories and offers from file to DB';

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
            Log::critical("Exception while importing file: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
