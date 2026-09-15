<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Relaticle\EmailIntegration\Actions\StoreInboundEmailAction;
use Relaticle\EmailIntegration\Models\WorkspaceInboundAddress;

final class ProcessInboundEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $rawDisk,
        public readonly string $rawPath,
        public readonly string $envelopeFrom,
        public readonly string $envelopeTo,
    ) {
        $this->onQueue('emails-sync');
    }

    public function handle(StoreInboundEmailAction $action): void
    {
        $address = WorkspaceInboundAddress::query()
            ->where('email', strtolower($this->envelopeTo))
            ->where('is_active', true)
            ->with('workspace')
            ->first();

        if (! $address instanceof WorkspaceInboundAddress) {
            return;
        }

        $action->execute($address, $this->envelopeFrom, $this->rawDisk, $this->rawPath);
    }
}
