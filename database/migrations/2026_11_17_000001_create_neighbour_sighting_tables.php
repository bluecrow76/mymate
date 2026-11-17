<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Neighbour data (stage 1 of link suggestions / topology tracking): what each polled device can
 * see of its neighbours, kept as **sightings** - a row is inserted the first time something is
 * seen and only its last_seen_at moves after that. Nothing is rewritten, so a change (a MAC on a
 * new port, a different LLDP neighbour on a port) shows up as a new row next to the old one, and
 * the old row simply stops being refreshed. A daily prune drops rows past the retention window.
 *
 * interface_id is resolved from the device's ifIndex when that interface is known, and nulled
 * (not cascaded) if the interface row goes away, so history survives a re-discovery.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The port's own MAC (ifPhysAddress). On a switch every port usually reports the bridge
        // MAC; on a router each port has its own.
        Schema::table('interfaces', function (Blueprint $table) {
            $table->macAddress('mac')->nullable()->after('description');
        });

        // Every IP a device owns (ipAddrTable), not just its management IP, so a neighbour seen
        // at another of its addresses still resolves to it (eg a router's gateway on each VLAN).
        Schema::create('device_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('interface_id')->nullable()->constrained('interfaces')->nullOnDelete();
            $table->ipAddress('ip');
            $table->unsignedSmallInteger('prefix_length')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->unique(['device_id', 'ip']);
            $table->index('ip');
        });

        // Discovery-protocol neighbours: LLDP (remote port known) and MikroTik MNDP (no remote
        // port). remote_key is what identifies the neighbour for de-duplication - the chassis
        // id, else the MAC, else the identity - so one neighbour seen repeatedly is one row.
        Schema::create('neighbour_sightings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('interface_id')->nullable()->constrained('interfaces')->nullOnDelete();
            $table->unsignedInteger('local_if_index')->default(0); // 0 = not reported
            $table->string('protocol', 8); // lldp | mndp
            $table->string('remote_key', 255);
            $table->string('remote_chassis_id', 255)->nullable();
            $table->macAddress('remote_mac')->nullable();
            $table->ipAddress('remote_ip')->nullable();
            $table->string('remote_name', 255)->nullable();
            $table->string('remote_port_id', 255)->nullable();
            $table->string('remote_port_description', 255)->nullable();
            $table->string('remote_platform', 255)->nullable();
            $table->string('remote_version', 255)->nullable();
            $table->json('remote_capabilities')->nullable(); // eg ["bridge","router"] (LLDP only)
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->unique(['device_id', 'protocol', 'local_if_index', 'remote_key'], 'neighbour_sightings_identity_unique');
            $table->index('remote_mac');
            $table->index('remote_ip');
        });

        // Bridge forwarding table: a MAC learned on a port (per VLAN when the device reports
        // it). `port` is the bridge port as reported; if_index is that port translated to an
        // interface (via dot1dBasePortTable, or the port itself where the two are the same).
        Schema::create('fdb_sightings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('interface_id')->nullable()->constrained('interfaces')->nullOnDelete();
            $table->unsignedInteger('port');
            $table->unsignedInteger('if_index')->nullable();
            $table->macAddress('mac');
            $table->unsignedSmallInteger('vlan')->default(0); // 0 = not reported
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->unique(['device_id', 'mac', 'vlan', 'port'], 'fdb_sightings_identity_unique');
            $table->index('mac');
        });

        // ARP: which MAC answers for an IP, as seen by a device (routers give the most).
        Schema::create('arp_sightings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('interface_id')->nullable()->constrained('interfaces')->nullOnDelete();
            $table->unsignedInteger('if_index')->nullable();
            $table->ipAddress('ip');
            $table->macAddress('mac');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->unique(['device_id', 'ip', 'mac'], 'arp_sightings_identity_unique');
            $table->index('mac');
            $table->index('ip');
        });

        // When neighbour data was last read from each device, and why it failed if it did - so
        // stage 2 can tell "nothing there" from "couldn't look".
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('neighbours_collected_at')->nullable();
            $table->string('neighbours_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['neighbours_collected_at', 'neighbours_error']);
        });
        Schema::dropIfExists('arp_sightings');
        Schema::dropIfExists('fdb_sightings');
        Schema::dropIfExists('neighbour_sightings');
        Schema::dropIfExists('device_addresses');
        Schema::table('interfaces', function (Blueprint $table) {
            $table->dropColumn('mac');
        });
    }
};
