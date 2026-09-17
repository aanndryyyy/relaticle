<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Relaticle\EmailIntegration\Actions\StoreMeetingAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Factories\NormalizedMeetingPayloadFactory;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

#[DeleteWhenMissingModels]
final class StoreMeetingJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly CalendarEventData $event,
        public readonly ?int $calendarSyncGeneration = null,
    ) {
        $this->onQueue('emails-sync');
    }

    public function handle(
        StoreMeetingAction $store,
        NormalizedMeetingPayloadFactory $factory,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->calendarSyncGeneration !== null
            && ! MailboxSyncTracker::isCalendarSyncGenerationCurrent($this->connectedAccount, $this->calendarSyncGeneration)) {
            return;
        }

        $payload = $factory->fromCalendarEvent($this->event, $this->connectedAccount->email_address);

        $store->execute($payload, $this->connectedAccount);
    }
}
