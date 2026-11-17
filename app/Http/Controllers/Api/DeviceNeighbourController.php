<?php

namespace App\Http\Controllers\Api;

use App\Actions\Neighbours\GetDeviceNeighbours;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/devices/{device}/neighbours - the neighbour data collected for one device (LLDP/MNDP
 * neighbours, MACs per port, ARP, its own addresses), for the device page's Neighbours tab.
 * `?history=1` adds rows no longer seen by the latest read.
 *
 * Restricted operators are refused (RestrictedAccess denies `devices.neighbours`): the data names
 * other devices' IPs, MACs and hostnames, which can reach beyond the maps they were granted.
 */
class DeviceNeighbourController extends Controller
{
    public function show(Request $request, Device $device, GetDeviceNeighbours $get): JsonResponse
    {
        $history = $request->boolean('history');

        return response()->json(['data' => $get($device, $history)]);
    }
}
