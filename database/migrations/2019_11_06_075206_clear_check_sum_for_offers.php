<?php

use Illuminate\Database\Migrations\Migration;
use App\Offer;

class ClearCheckSumForOffers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Offer::update(['check_sum' => '']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
