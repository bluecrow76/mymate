<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuthSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The access-policy settings (admin): the mandatory-passkey toggle and whether restricted operators
 * may open the Outages page. `show`
 * also reports how many operators would be forced to enrol if it were switched on, so the UI can
 * warn before flipping it (wallboard/kiosk accounts on TVs are the ones to watch - mark them exempt).
 */
class SecuritySettingController extends Controller
{
    public function show(AuthSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->publicView() + [
            'affected_operators' => $this->affectedOperators(),
        ]]);
    }

    public function update(Request $request, AuthSettings $settings): JsonResponse
    {
        // Either flag on its own (the UI saves each toggle as it's flipped), at least one of them.
        $data = $request->validate([
            'passkey_required' => ['required_without:restricted_outages', 'boolean'],
            'restricted_outages' => ['required_without:passkey_required', 'boolean'],
        ]);
        if (array_key_exists('passkey_required', $data)) {
            $settings->setPasskeyRequired($data['passkey_required']);
        }
        if (array_key_exists('restricted_outages', $data)) {
            $settings->setRestrictedOutages($data['restricted_outages']);
        }

        return response()->json(['data' => $settings->publicView()]);
    }

    /** Operators who'd be forced to enrol a passkey: not exempt, and none registered yet. */
    private function affectedOperators(): int
    {
        return User::where('passkey_exempt', false)->whereDoesntHave('passkeys')->count();
    }
}
