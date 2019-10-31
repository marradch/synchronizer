<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DeleteAlbumTask extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vk_delete_album__tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->enum('mode', ['soft', 'hard']);
            $table->timestamps();
        });

        Schema::create('vk_delete_album__albums', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('album_id');
            $table->integer('task_id')->unsigned();
            $table->boolean('is_done')->default(0);
            $table->text('vk_loading_error')->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('vk_delete_album__tasks')->onDelete('cascade');
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
