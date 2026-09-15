<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Workspace;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\Email;

final class InboundEmailThreadResolver
{
    public function resolve(
        Workspace $workspace,
        ?string $messageId,
        ?string $inReplyTo,
        ?string $referencesHeader,
    ): string {
        $parentMessageId = $this->findParentMessageId($workspace, $inReplyTo, $referencesHeader);

        if ($parentMessageId !== null) {
            $parent = Email::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('rfc_message_id', $parentMessageId)
                ->first();

            if ($parent instanceof Email && filled($parent->thread_id)) {
                return (string) $parent->thread_id;
            }
        }

        $root = $parentMessageId ?? $messageId ?? (string) Str::ulid();

        return 'inbound:'.$root;
    }

    private function findParentMessageId(
        Workspace $workspace,
        ?string $inReplyTo,
        ?string $referencesHeader,
    ): ?string {
        if (filled($inReplyTo)) {
            $normalized = $this->normalizeMessageId($inReplyTo);

            if ($normalized !== null && $this->messageExistsInWorkspace($workspace, $normalized)) {
                return $normalized;
            }
        }

        if (blank($referencesHeader)) {
            return null;
        }

        $ids = preg_split('/\s+/', trim($referencesHeader)) ?: [];

        foreach (array_reverse($ids) as $id) {
            $normalized = $this->normalizeMessageId($id);

            if ($normalized !== null && $this->messageExistsInWorkspace($workspace, $normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    private function messageExistsInWorkspace(Workspace $workspace, string $messageId): bool
    {
        return Email::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('rfc_message_id', $messageId)
            ->exists();
    }

    private function normalizeMessageId(?string $messageId): ?string
    {
        $messageId = trim((string) $messageId);

        return $messageId !== '' ? $messageId : null;
    }
}
