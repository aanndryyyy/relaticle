<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\Chat\Models\AgentConversation;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Description('Delete chat attachments nobody sent within a day, with any empty conversation the upload opened')]
#[Signature('chat:purge-unsent-attachments {--hours=24 : Delete unsent attachments older than this many hours}')]
final class PurgeUnsentAttachmentsCommand extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $unsent = Media::query()
            ->where('collection_name', AgentConversation::ATTACHMENTS_MEDIA_COLLECTION)
            ->whereNull('custom_properties->sent_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($unsent as $media) {
            $this->info("Purging attachment {$media->uuid}");

            $conversation = $media->model;
            $media->delete();

            if ($conversation instanceof AgentConversation && ! $conversation->isSetup() && ! $conversation->messages()->exists()) {
                $conversation->delete();
            }
        }

        $this->comment("Purged {$unsent->count()} attachment(s).");

        return self::SUCCESS;
    }
}
