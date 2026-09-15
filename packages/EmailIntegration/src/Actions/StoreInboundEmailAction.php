<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailLabel;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\WorkspaceInboundAddress;
use Relaticle\EmailIntegration\Services\EmailClassifier;
use Relaticle\EmailIntegration\Services\ForwardingBlocklistMatcher;
use Relaticle\EmailIntegration\Services\ForwardingPrivacyService;
use Relaticle\EmailIntegration\Services\InboundEmailAttachmentStore;
use Relaticle\EmailIntegration\Services\InboundEmailMemberResolver;
use Relaticle\EmailIntegration\Services\InboundEmailThreadResolver;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

final readonly class StoreInboundEmailAction
{
    public function __construct(
        private InboundEmailMemberResolver $memberResolver,
        private InboundEmailThreadResolver $threadResolver,
        private InboundEmailAttachmentStore $attachmentStore,
        private ForwardingBlocklistMatcher $blocklist,
        private ForwardingPrivacyService $forwardingPrivacy,
        private SeedForwardingFullAccessSharesAction $seedShares,
        private LinkEmailAction $linkEmail,
    ) {}

    public function execute(
        WorkspaceInboundAddress $inboundAddress,
        string $envelopeFrom,
        string $rawDisk,
        string $rawPath,
    ): ?Email {
        $workspace = $inboundAddress->workspace;
        $submittingMember = $this->memberResolver->resolve($workspace, $envelopeFrom);

        if (! $submittingMember instanceof User) {
            return null;
        }

        $raw = Storage::disk($rawDisk)->get($rawPath);

        if (! is_string($raw) || $raw === '') {
            throw new \RuntimeException('Could not read inbound raw email.');
        }

        $message = (new MailMimeParser)->parse($raw, false);

        $rfcMessageId = $this->normalizeMessageId($message->getHeaderValue('Message-ID'));

        if ($rfcMessageId !== null) {
            $duplicate = Email::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $submittingMember->getKey())
                ->whereNull('connected_account_id')
                ->where('rfc_message_id', $rfcMessageId)
                ->exists();

            if ($duplicate) {
                return null;
            }
        }

        $subject = $message->getSubject() ?: __('filament/pages/email-access-requests.request.no_subject');
        $textBody = $message->getTextContent() ?? '';
        $htmlBody = $message->getHtmlContent();
        $snippet = Str::limit(trim(strip_tags($textBody !== '' ? $textBody : (string) $htmlBody)), 255, '');
        $sentAt = $this->parseSentAt($message);
        $inReplyTo = $this->normalizeMessageId($message->getHeaderValue('In-Reply-To'));
        $references = $message->getHeaderValue('References');
        $threadId = $this->threadResolver->resolve($workspace, $rfcMessageId, $inReplyTo, $references);
        $participants = $this->participants($message);
        $participantAddresses = array_map(
            fn (array $participant): string => $participant['email_address'],
            $participants,
        );

        if ($this->blocklist->isBlocked($submittingMember->getKey(), $workspace->getKey(), $participantAddresses)) {
            return null;
        }

        $hasAttachments = count($message->getAllAttachmentParts()) > 0;

        $storedAttachmentPaths = [];

        try {
            return DB::transaction(function () use (
                $workspace,
                $submittingMember,
                $inboundAddress,
                $rfcMessageId,
                $threadId,
                $inReplyTo,
                $subject,
                $snippet,
                $textBody,
                $htmlBody,
                $sentAt,
                $participants,
                $hasAttachments,
                $message,
                &$storedAttachmentPaths,
            ): Email {
                $email = Email::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $submittingMember->getKey(),
                    'connected_account_id' => null,
                    'rfc_message_id' => $rfcMessageId,
                    'provider_message_id' => 'inbound-'.Str::ulid(),
                    'thread_id' => $threadId,
                    'in_reply_to' => $inReplyTo,
                    'subject' => $subject,
                    'snippet' => $snippet,
                    'sent_at' => $sentAt,
                    'direction' => EmailDirection::INBOUND,
                    'folder' => EmailFolder::Inbox,
                    'status' => EmailStatus::SYNCED,
                    'has_attachments' => $hasAttachments,
                    'privacy_tier' => $this->forwardingPrivacy->defaultSharingTier($submittingMember, $workspace),
                    'creation_source' => EmailCreationSource::BCC_INBOUND,
                ]);

                $email->body()->create([
                    'body_text' => $textBody !== '' ? $textBody : null,
                    'body_html' => $htmlBody,
                ]);

                foreach ($participants as $participant) {
                    EmailParticipant::query()->create([
                        'email_id' => $email->getKey(),
                        'email_address' => $participant['email_address'],
                        'name' => $participant['name'],
                        'role' => $participant['role'],
                    ]);
                }

                $storedAttachmentPaths = $this->attachmentStore->storeAll($message, $email);

                $teamUserEmails = $workspace->allUsers()
                    ->pluck('email')
                    ->map(fn (string $e): string => strtolower($e));

                $participantAddresses = collect($participants)
                    ->pluck('email_address')
                    ->map(fn (string $e): string => strtolower($e));

                $isInternal = $participantAddresses->isNotEmpty() && $participantAddresses->every(
                    fn (string $address): bool => $teamUserEmails->contains($address),
                );

                $email->updateQuietly(['is_internal' => $isInternal]);

                $fetched = new FetchedEmailData(
                    providerMessageId: (string) $email->provider_message_id,
                    rfcMessageId: $rfcMessageId ?? '',
                    threadId: $threadId,
                    inReplyTo: $inReplyTo,
                    subject: $subject,
                    snippet: $snippet,
                    sentAt: $sentAt ?? now(),
                    direction: EmailDirection::INBOUND,
                    folder: EmailFolder::Inbox,
                    hasAttachments: $hasAttachments,
                    isRead: false,
                    bodyText: $textBody,
                    bodyHtml: $htmlBody ?? '',
                    participants: $participants,
                    attachments: [],
                );

                EmailLabel::query()->create([
                    'email_id' => $email->getKey(),
                    'label' => resolve(EmailClassifier::class)->classify($fetched, $isInternal)->value,
                    'source' => 'system',
                    'created_at' => now(),
                ]);

                $this->linkEmail->execute($email);

                $this->seedShares->execute($email, $submittingMember, $workspace);

                $inboundAddress->forceFill(['last_received_at' => now()])->save();

                return $email;
            });
        } catch (Throwable $exception) {
            Storage::disk(EmailAttachment::DISK)->delete($storedAttachmentPaths);

            throw $exception;
        }
    }

    /**
     * @return list<array{email_address: string, name: string|null, role: string}>
     */
    private function participants(IMessage $message): array
    {
        $participants = [
            ...$this->addressesFromHeader($message, 'From', EmailParticipantRole::FROM),
            ...$this->addressesFromHeader($message, 'To', EmailParticipantRole::TO),
            ...$this->addressesFromHeader($message, 'Cc', EmailParticipantRole::CC),
            ...$this->addressesFromHeader($message, 'Bcc', EmailParticipantRole::BCC),
        ];

        if ($participants !== []) {
            return $participants;
        }

        return [
            [
                'email_address' => 'unknown@invalid',
                'name' => null,
                'role' => EmailParticipantRole::FROM->value,
            ],
        ];
    }

    /**
     * @return list<array{email_address: string, name: string|null, role: string}>
     */
    private function addressesFromHeader(IMessage $message, string $headerName, EmailParticipantRole $role): array
    {
        $header = $message->getHeader($headerName);

        if (! $header instanceof AddressHeader) {
            return [];
        }

        $rows = [];

        foreach ($header->getAddresses() as $address) {
            $email = strtolower(trim($address->getEmail()));

            if ($email === '') {
                continue;
            }

            $rows[] = [
                'email_address' => $email,
                'name' => $address->getName() !== '' ? $address->getName() : null,
                'role' => $role->value,
            ];
        }

        return $rows;
    }

    private function normalizeMessageId(?string $messageId): ?string
    {
        $messageId = trim((string) $messageId);

        return $messageId !== '' ? $messageId : null;
    }

    private function parseSentAt(IMessage $message): ?CarbonInterface
    {
        $dateHeader = $message->getHeader('Date');

        if ($dateHeader === null) {
            return null;
        }

        $value = $dateHeader->getValue();

        try {
            return Date::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
