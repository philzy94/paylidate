<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A settlement id is an idempotency key: crediting the same one twice must be impossible.
     */
    public function up(): void
    {
        $duplicates = DB::table('walletsettlement_ids')
            ->select('settlementId', DB::raw('MIN(id) as keep_id'))
            ->groupBy('settlementId')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            DB::table('walletsettlement_ids')
                ->where('settlementId', $row->settlementId)
                ->where('id', '!=', $row->keep_id)
                ->delete();
        }

        Schema::table('walletsettlement_ids', function (Blueprint $table) {
            $table->unique('settlementId');
        });
    }

    public function down(): void
    {
        Schema::table('walletsettlement_ids', function (Blueprint $table) {
            $table->dropUnique(['settlementId']);
        });
    }
};
