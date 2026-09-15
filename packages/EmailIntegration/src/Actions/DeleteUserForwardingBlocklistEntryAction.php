<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\UserForwardingBlocklist;

final readonly class DeleteUserForwardingBlocklistEntryAction
{
    public function execute(User $user, Workspace $workspace, string $entryId): void
    {
        UserForwardingBlocklist::query()
            ->where('user_id', $user->getKey())
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($entryId)
            ->firstOrFail()
            ->delete();
    }
}
