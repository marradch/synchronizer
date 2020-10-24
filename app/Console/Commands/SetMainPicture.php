<?php

namespace App\Console\Commands;

use App\Offer;
use App\Picture;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class SetMainPicture extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'picture:set-main';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        $offers = Offer::where('is_excluded', false);

        $output = new ConsoleOutput();
        $progress = new ProgressBar($output, $offers->count());
        $progress->start();
        foreach ($offers->cursor() as $offer) {
            $progress->advance();
            $picturesVKIds = $offer->pictures
                ->where('status', 'added')
                ->where('synchronized', true)
                ->pluck('vk_id');
            $picturesVKIds = $picturesVKIds->toArray();
            if (count($picturesVKIds) > 0) {
                $mainPicture = array_shift($picturesVKIds);
                Picture::where('vk_id', $mainPicture)->update(['is_main' => 1]);
            }
        }
        $progress->finish();
    }
}
