<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\MediaCollection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class DiscardPendingUpload
{
    public function execute(User $user, Workspace $workspace, string $uuid): void
    {
        abort_unless($user->belongsToWorkspace($workspace), 403);

        DB::transaction(function () use ($workspace, $uuid): void {
            Media::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('uuid', $uuid)
                ->where('collection_name', MediaCollection::PendingUploads->value)
                ->lockForUpdate()
                ->first()?->delete();
        });
    }
}
