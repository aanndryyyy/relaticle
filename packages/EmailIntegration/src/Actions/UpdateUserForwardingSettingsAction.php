<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\UserForwardingSettings;

final readonly class UpdateUserForwardingSettingsAction
{
    public function execute(User $user, Workspace $workspace, EmailPrivacyTier $sharingTier): UserForwardingSettings
    {
        return UserForwardingSettings::query()->updateOrCreate([
            'user_id' => $user->getKey(),
            'workspace_id' => $workspace->getKey(),
        ], [
            'sharing_tier' => $sharingTier->value,
        ]);
    }
}
