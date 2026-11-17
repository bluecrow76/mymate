<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MNDP rows the table dropped mid-walk were stored as nameless copies of neighbours already seen
 * (no local interface, keyed by the neighbour's IP). The reader skips those now; this removes
 * the ones the first night stored, and blanks the 0.0.0.0 RouterOS reports for a neighbour that
 * announced no address. Data only - nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('neighbour_sightings')
            ->where('protocol', 'mndp')
            ->where(fn ($q) => $q->where('local_if_index', 0)
                ->orWhere(fn ($q) => $q->whereNull('remote_mac')->where(fn ($q) => $q->whereNull('remote_name')->orWhere('remote_name', ''))))
            ->delete();

        DB::table('neighbour_sightings')->where('protocol', 'mndp')->where('remote_ip', '0.0.0.0')->update(['remote_ip' => null]);
    }

    public function down(): void {}
};
