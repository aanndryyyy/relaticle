<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\MediaCollection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaLookup;

final readonly class DiscardPendingUpload
{
    public function __construct(private MediaLookup $lookup) {}

    public function execute(User $user, Workspace $workspace, string $uuid): void
    {
        abort_unless($user->belongsToWorkspace($workspace), 403);

        $media = $this->lookup->find((string) $workspace->getKey(), $uuid);

        if ($media?->collection_name === MediaCollection::PendingUploads->value) {
            $media->delete();
        }
    }
}
