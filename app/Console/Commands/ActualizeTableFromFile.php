<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\VKSynchronizerService;

class ActualizeTableFromFile extends Command
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
        $VKSynchronizerService = new VKSynchronizerService();
        $VKSynchronizerService->processFile();
    }
}
