<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Workspace;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\WorkspaceInboundAddress;

final class WorkspaceInboundAddressService
{
    public function activeFor(Workspace $workspace): ?WorkspaceInboundAddress
    {
        return WorkspaceInboundAddress::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    public function ensureFor(Workspace $workspace): WorkspaceInboundAddress
    {
        $existing = $this->activeFor($workspace);

        if ($existing instanceof WorkspaceInboundAddress) {
            if ($this->isLegacyOpaqueLocalPart($existing->local_part)) {
                return $this->migrateLegacyAddress($existing);
            }

            return $existing;
        }

        return $this->createFor($workspace);
    }

    public function createFor(Workspace $workspace): WorkspaceInboundAddress
    {
        $domain = (string) config('inbound-email.domain');
        $localPart = $this->uniqueLocalPart((string) $workspace->slug);

        return WorkspaceInboundAddress::query()->create([
            'workspace_id' => $workspace->getKey(),
            'local_part' => $localPart,
            'email' => $localPart.'@'.$domain,
            'is_active' => true,
        ]);
    }

    public function rotate(WorkspaceInboundAddress $address): WorkspaceInboundAddress
    {
        $address->update(['is_active' => false]);

        return $this->createFor($address->workspace);
    }

    private function migrateLegacyAddress(WorkspaceInboundAddress $legacy): WorkspaceInboundAddress
    {
        $legacy->update(['is_active' => false]);

        return $this->createFor($legacy->workspace);
    }

    private function isLegacyOpaqueLocalPart(string $localPart): bool
    {
        return (bool) preg_match('/^w_[a-z0-9]{26}_[a-z0-9]{16}$/', $localPart);
    }

    private function uniqueLocalPart(string $base): string
    {
        $candidate = Str::lower(Str::slug($base));

        if ($candidate === '') {
            $candidate = 'workspace';
        }

        if (! WorkspaceInboundAddress::query()->where('local_part', $candidate)->exists()) {
            return $candidate;
        }

        $suffix = 2;

        while (WorkspaceInboundAddress::query()->where('local_part', "{$candidate}-{$suffix}")->exists()) {
            $suffix++;
        }

        return "{$candidate}-{$suffix}";
    }
}
