<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddingServiceFieldsForModels extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pictures', function (Blueprint $table) {
            $table->dropColumn('status_date');
        });

        Schema::table('pictures', function (Blueprint $table) {
            $table->boolean('delete_sign')->default(0)->after('url');
            $table->text('vk_loading_error')->after('vk_id');
            $table->dateTime('status_date')->default('0001-01-01 00:00:00');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('status_date');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('delete_sign')->default(0)->after('check_sum');
            $table->text('vk_loading_error')->after('vk_id');
            $table->dateTime('status_date')->default('0001-01-01 00:00:00');
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('status_date');
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->boolean('delete_sign')->default(0)->after('check_sum');
            $table->text('vk_loading_error')->after('vk_id');
            $table->dateTime('status_date')->default('0001-01-01 00:00:00');
        });
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
