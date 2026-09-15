<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\UserForwardingFullAccessGrant;

final readonly class UpdateUserForwardingFullAccessGrantsAction
{
    /**
     * @param  list<string>  $grantedUserIds
     */
    public function execute(User $user, Workspace $workspace, array $grantedUserIds): void
    {
        $memberIds = $workspace->allUsers()
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
        $validIds = array_values(array_intersect($grantedUserIds, $memberIds));
        $validIds = array_values(array_filter(
            $validIds,
            fn (string $id): bool => $id !== $user->getKey(),
        ));

        UserForwardingFullAccessGrant::query()
            ->where('user_id', $user->getKey())
            ->where('workspace_id', $workspace->getKey())
            ->delete();

        foreach ($validIds as $granteeId) {
            UserForwardingFullAccessGrant::query()->create([
                'user_id' => $user->getKey(),
                'workspace_id' => $workspace->getKey(),
                'granted_user_id' => $granteeId,
            ]);
        }
    }
}
