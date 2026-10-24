<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ThirdPartyApiMiddleware looks partners up by this column; the original table had none.
        Schema::table('apikeys', function (Blueprint $table) {
            $table->string('API_key')->unique()->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('apikeys', function (Blueprint $table) {
            $table->dropUnique(['API_key']);
            $table->dropColumn('API_key');
        });
    }
};
