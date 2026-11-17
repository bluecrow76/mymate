<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Outages page's time range and histogram read outages by start time across every device;
 * the only index so far is (device_id, started_at), which can't serve that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outages', function (Blueprint $table) {
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('outages', function (Blueprint $table) {
            $table->dropIndex(['started_at']);
        });
    }
};
