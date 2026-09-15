<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\WorkspaceInboundAddress;
use Relaticle\EmailIntegration\Services\WorkspaceInboundAddressService;

final readonly class EnsureWorkspaceInboundAddressAction
{
    public function __construct(private WorkspaceInboundAddressService $addresses) {}

    public function execute(Workspace $workspace): WorkspaceInboundAddress
    {
        return $this->addresses->ensureFor($workspace);
    }
}
