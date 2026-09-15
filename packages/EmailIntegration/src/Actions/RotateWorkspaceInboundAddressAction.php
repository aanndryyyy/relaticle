<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\WorkspaceInboundAddress;
use Relaticle\EmailIntegration\Services\WorkspaceInboundAddressService;

final readonly class RotateWorkspaceInboundAddressAction
{
    public function __construct(private WorkspaceInboundAddressService $addresses) {}

    public function execute(Workspace $workspace, User $actor): WorkspaceInboundAddress
    {
        abort_unless(
            $actor->ownsWorkspace($workspace) || $actor->hasWorkspaceRole($workspace, WorkspaceRole::Admin->value),
            403,
        );

        $active = $this->addresses->activeFor($workspace);

        if (! $active instanceof WorkspaceInboundAddress) {
            return $this->addresses->createFor($workspace);
        }

        return $this->addresses->rotate($active);
    }
}
