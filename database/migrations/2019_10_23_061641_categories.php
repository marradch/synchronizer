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
            $table->bigInteger('shop_parent_id')->default(0);
            $table->string('name');
            $table->string('prepared_name')->default('');
            $table->string('check_sum');
            $table->enum('status', ['added', 'edited', 'deleted']);
            $table->dateTime('status_date');
            $table->boolean('synchronized')->default(0);
            $table->dateTime('synchronize_date')->default('0001-01-01 00:00:00');
            $table->bigInteger('vk_id')->default(0);
            $table->enum('can_load_to_vk', ['yes', 'no', 'default'])->default('default');
            $table->timestamps();
            $table->index('shop_id');
            $table->index('shop_parent_id');
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
