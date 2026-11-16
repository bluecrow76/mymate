<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where devices added from a subnet's discovery results go: which map (null = the default map, as
 * before) and where on it (see App\Support\MapPlacement). Deleting the map puts the subnet back on
 * the default map rather than failing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->foreignId('map_id')->nullable()->after('agent_id')->constrained()->nullOnDelete();
            $table->string('placement', 16)->default('right')->after('map_id');
        });
    }

    public function down(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('map_id');
            $table->dropColumn('placement');
        });
    }
};
