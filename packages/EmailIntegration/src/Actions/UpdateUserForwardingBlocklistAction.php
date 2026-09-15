<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\UserForwardingBlocklist;

final readonly class UpdateUserForwardingBlocklistAction
{
    /**
     * @param  list<array{type: string, value: string}>  $blocklist
     */
    public function execute(User $user, Workspace $workspace, array $blocklist): void
    {
        UserForwardingBlocklist::query()
            ->where('user_id', $user->getKey())
            ->where('workspace_id', $workspace->getKey())
            ->delete();

        foreach ($blocklist as $entry) {
            $value = strtolower(trim((string) $entry['value']));

            if ($value === '') {
                continue;
            }

            UserForwardingBlocklist::query()->create([
                'user_id' => $user->getKey(),
                'workspace_id' => $workspace->getKey(),
                'type' => $entry['type'],
                'value' => $value,
            ]);
        }
    }
}
