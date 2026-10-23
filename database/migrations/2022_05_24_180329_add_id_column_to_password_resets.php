<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIdColumnToPasswordResets extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('password_resets', 'id')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite cannot add a primary key column to an existing table.
            Schema::dropIfExists('password_resets');
            Schema::create('password_resets', function (Blueprint $table) {
                $table->id();
                $table->string('email')->index();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });

            return;
        }

        Schema::table('password_resets', function (Blueprint $table) {
            $table->id();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('password_resets', function (Blueprint $table) {
            //
        });
    }
}
