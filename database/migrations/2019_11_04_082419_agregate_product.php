<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AgregateProduct extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('params', 500)->after('vendor_code')->default('');
            $table->text('origin_description')->after('params')->nullable();
            $table->boolean('is_excluded')->after('delete_sign')->default(0);
            $table->boolean('is_aggregate')->after('is_excluded')->default(0);
            $table->boolean('synch_with_aggregate')->after('is_aggregate')->default(0);
            $table->index('vendor_code');
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
