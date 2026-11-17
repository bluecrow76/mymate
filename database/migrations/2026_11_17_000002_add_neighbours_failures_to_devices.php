<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failed or partial neighbour reads in a row, so the dispatcher can try again sooner than the
 * full interval (10, 20, 40 minutes, then the interval). Reset by a whole read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedSmallInteger('neighbours_failures')->default(0)->after('neighbours_error');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('neighbours_failures');
        });
    }
};
