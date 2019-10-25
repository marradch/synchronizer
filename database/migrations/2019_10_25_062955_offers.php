<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Offers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('shop_id');
            $table->bigInteger('shop_category_id');
            $table->string('name');
            $table->integer('price')->default(0);
            $table->string('vendor_code');
            $table->text('description');
            $table->string('check_sum');
            $table->enum('status', ['added', 'edited', 'deleted', 'rejected']);
            $table->dateTime('status_date');
            $table->boolean('synchronized')->default(0);
            $table->dateTime('synchronize_date')->default('0001-01-01 00:00:00');
            $table->bigInteger('vk_id')->default(0);
            $table->timestamps();
            $table->index('shop_id');
            $table->index('shop_category_id');
        });

        Schema::create('pictures', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('offer_id')->unsigned();
            $table->string('url');
            $table->enum('status', ['added', 'deleted']);
            $table->dateTime('status_date');
            $table->boolean('synchronized')->default(0);
            $table->dateTime('synchronize_date')->default('0001-01-01 00:00:00');
            $table->bigInteger('vk_id')->default(0);
            $table->timestamps();
            $table->foreign('offer_id')->references('id')->on('offers')->onDelete('cascade');
            $table->index('url');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('offers');
        Schema::drop('pictures');
    }
}
