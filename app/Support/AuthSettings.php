<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Operator-editable access policy, kept in a single `settings` row (key {@see self::KEY}) so it can
 * be changed in-app (Settings > Security):
 *
 *  - passkey_required: whether passkeys (WebAuthn) are mandatory, falling back to the config default
 *    (`mymate.auth.passkey_required`) when unset. When on, every operator who has no passkey and
 *    isn't marked `passkey_exempt` is forced to enrol one before they can do anything (enforced by
 *    the EnsurePasskeyVerified middleware). API keys (bearer tokens) are never affected.
 *  - restricted_outages: whether restricted operators (GitHub #28) may open the Outages page. Off by
 *    default, which is how it has always been; when on they see outages for the devices on their
 *    maps only (OutageController scopes the rows, RestrictedAccess lets the route through).
 *
 * Each setter changes its own flag and leaves the others as they were.
 */
class AuthSettings
{
    private const KEY = 'auth.security';

    public function passkeyRequired(): bool
    {
        return (bool) ($this->stored()['passkey_required'] ?? config('mymate.auth.passkey_required', false));
    }

    public function setPasskeyRequired(bool $required): void
    {
        $this->patch(['passkey_required' => $required]);
    }

    public function restrictedOutages(): bool
    {
        return (bool) ($this->stored()['restricted_outages'] ?? false);
    }

    public function setRestrictedOutages(bool $allowed): void
    {
        $this->patch(['restricted_outages' => $allowed]);
    }

    /** @return array<string,bool> */
    public function publicView(): array
    {
        return [
            'passkey_required' => $this->passkeyRequired(),
            'restricted_outages' => $this->restrictedOutages(),
        ];
    }

    /** @return array<string,mixed> */
    private function stored(): array
    {
        $raw = Setting::where('key', self::KEY)->first()?->value;

        return is_array($raw) ? $raw : [];
    }

    /** @param array<string,bool> $values */
    private function patch(array $values): void
    {
        Setting::updateOrCreate(
            ['key' => self::KEY],
            ['value' => array_merge($this->stored(), $values), 'type' => 'json'],
        );
    }
}
