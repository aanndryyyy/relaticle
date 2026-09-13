<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\MediaCollection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaPaths;

final readonly class DiscardPendingUpload
{
    public function __construct(private MediaPaths $paths) {}

    public function execute(User $user, Workspace $workspace, string $path): void
    {
        abort_unless($user->belongsToWorkspace($workspace), 403);

        $media = $this->paths->find((string) $workspace->getKey(), $path);

        if ($media?->collection_name === MediaCollection::PendingUploads->value) {
            $media->delete();
        }
    }
}
