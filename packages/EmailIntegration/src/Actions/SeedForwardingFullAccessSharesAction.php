<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\UserForwardingFullAccessGrant;
use Relaticle\EmailIntegration\Services\EmailSharingService;

final readonly class SeedForwardingFullAccessSharesAction
{
    public function __construct(private EmailSharingService $sharing) {}

    public function execute(Email $email, User $owner, Workspace $workspace): void
    {
        $granteeIds = UserForwardingFullAccessGrant::query()
            ->where('user_id', $owner->getKey())
            ->where('workspace_id', $workspace->getKey())
            ->pluck('granted_user_id');

        foreach ($granteeIds as $granteeId) {
            $grantee = $workspace->allUsers()->firstWhere('id', $granteeId);

            if (! $grantee instanceof User) {
                continue;
            }

            $this->sharing->shareEmail($email, $owner, $grantee, EmailPrivacyTier::FULL);
        }
    }
}
