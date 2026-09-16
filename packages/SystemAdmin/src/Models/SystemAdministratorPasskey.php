<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

/**
 * @property string $user_id
 * @property-read SystemAdministrator $user
 */
final class SystemAdministratorPasskey extends Passkey
{
    use HasFactory;

    protected $table = 'system_administrator_passkeys';

    /**
     * The host staff credentials bind to, and the only place it is decided. It
     * follows this panel, not `passkeys.relying_party_id`, which Fortify pins to
     * the customer panel.
     */
    public static function relyingPartyId(): string
    {
        $domain = config('app.sysadmin_domain');

        if (is_string($domain) && $domain !== '') {
            return $domain;
        }

        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /**
     * Whether a browser can tell a staff credential from a customer one. Sharing a
     * relying party means the sign-in picker offers both and the staff endpoint
     * rejects whichever customer credential it is handed, so the feature is offered
     * only once SYSADMIN_DOMAIN separates the two.
     */
    public static function hasDedicatedRelyingParty(): bool
    {
        return self::relyingPartyId() !== Passkeys::relyingPartyId();
    }

    /**
     * @return BelongsTo<SystemAdministrator, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(SystemAdministrator::class, 'user_id');
    }
}
