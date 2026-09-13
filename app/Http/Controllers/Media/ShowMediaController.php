<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Support\Media\UploadAllowlist;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ShowMediaController
{
    public function __invoke(Media $media): StreamedResponse
    {
        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();

        abort_unless($disk->exists($path), 404);

        $name = (string) $media->getCustomProperty('original_name', $media->file_name);
        $disposition = UploadAllowlist::isImage((string) $media->mime_type) ? 'inline' : 'attachment';

        return $disk->response($path, $name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ], $disposition);
    }
}
