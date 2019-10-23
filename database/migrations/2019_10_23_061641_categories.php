<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Categories extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('shop_id');
            $table->bigInteger('shop_parent_id');
            $table->bigInteger('vk_id');
            $table->string('name');
            $table->string('prepared_name');
            $table->enum('status', ['added', 'edited', 'deleted']);
            $table->dateTime('status_date');
            $table->boolean('synchronized');
            $table->dateTime('synchronize_date');
            $table->string('check_sum');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('categories');
    }
}
