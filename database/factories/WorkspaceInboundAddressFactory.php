<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\WorkspaceInboundAddress;

/**
 * @extends Factory<WorkspaceInboundAddress>
 */
final class WorkspaceInboundAddressFactory extends Factory
{
    protected $model = WorkspaceInboundAddress::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'local_part' => 'placeholder',
            'email' => 'placeholder@example.test',
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (WorkspaceInboundAddress $address): void {
            if ($address->local_part !== 'placeholder') {
                return;
            }

            $workspace = $address->workspace;

            if ($workspace === null) {
                return;
            }

            $localPart = Str::lower(Str::slug((string) $workspace->slug));
            if ($localPart === '') {
                $localPart = 'workspace';
            }
            $domain = (string) config('inbound-email.domain', 'relaticle.email');

            $address->local_part = $localPart;
            $address->email = $localPart.'@'.$domain;
        });
    }
}
