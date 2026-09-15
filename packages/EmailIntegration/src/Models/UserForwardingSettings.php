<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use App\Models\User;
use Database\Factories\UserForwardingSettingsFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

/**
 * @property EmailPrivacyTier $sharing_tier
 */
final class UserForwardingSettings extends Model
{
    /**
     * @use HasFactory<UserForwardingSettingsFactory>
     */
    use HasFactory, HasUlids, HasWorkspace;

    protected static function newFactory(): UserForwardingSettingsFactory
    {
        return UserForwardingSettingsFactory::new();
    }

    protected $fillable = [
        'user_id',
        'workspace_id',
        'sharing_tier',
    ];

    protected function casts(): array
    {
        return [
            'sharing_tier' => EmailPrivacyTier::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
