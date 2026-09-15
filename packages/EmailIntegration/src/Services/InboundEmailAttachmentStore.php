<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Symfony\Component\Mime\MimeTypes;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message\IMessagePart;

final class InboundEmailAttachmentStore
{
    /**
     * @return list<string> stored paths for rollback
     */
    public function storeAll(IMessage $message, Email $email): array
    {
        $storedPaths = [];
        $attachments = array_slice($message->getAllAttachmentParts(), 0, (int) config('inbound-email.max_attachments'));

        foreach ($attachments as $index => $attachment) {
            $path = $this->storeOne($attachment, $email, (int) $index);

            if ($path === null) {
                continue;
            }

            $storedPaths[] = $path;
        }

        return $storedPaths;
    }

    private function storeOne(IMessagePart $attachment, Email $email, int $index): ?string
    {
        $stream = $attachment->getContentStream();

        if (! $stream instanceof StreamInterface) {
            return null;
        }

        $contents = (string) $stream;
        $size = strlen($contents);
        $maxBytes = (int) config('inbound-email.max_attachment_bytes');

        if ($size === 0 || $size > $maxBytes) {
            return null;
        }

        $filename = $this->safeFilename($attachment->getFilename(), $index, $attachment->getContentType());
        $path = 'inbound-email-attachments/'.$email->getKey().'/'.Str::ulid().'_'.$filename;

        Storage::disk(EmailAttachment::DISK)->put($path, $contents);

        $mimeType = $attachment->getContentType('application/octet-stream');
        $isInline = strtolower((string) $attachment->getContentDisposition('')) === 'inline'
            || str_contains(strtolower((string) $attachment->getContentDisposition('')), 'inline');

        $email->attachments()->create([
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $size,
            'content_id' => $attachment->getContentId(),
            'is_inline' => $isInline,
            'provider_attachment_id' => null,
            'storage_path' => $path,
        ]);

        return $path;
    }

    private function safeFilename(?string $filename, int $index, string $mimeType): string
    {
        $filename = basename(str_replace('\\', '/', (string) $filename));
        $filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: "attachment-{$index}";

        if (! str_contains($filename, '.')) {
            $extensions = MimeTypes::getDefault()->getExtensions($mimeType);
            $extension = $extensions[0] ?? 'bin';
            $filename .= '.'.$extension;
        }

        return Str::limit($filename, 180, '');
    }
}
