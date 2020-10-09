<?php

use App\Offer;
use App\Picture;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class AddIsMainPicture extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
//        Schema::table('pictures', function (Blueprint $table) {
//            $table->boolean('is_main')->default(0)->after('offer_id');
//        });
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

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pictures', function (Blueprint $table) {
            $table->dropColumn('is_main');
        });
    }
}
