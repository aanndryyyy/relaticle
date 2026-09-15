<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Actions\StoreInboundEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\WorkspaceInboundAddress;
use Relaticle\EmailIntegration\Services\WorkspaceInboundAddressService;

mutates(StoreInboundEmailAction::class);

beforeEach(function (): void {
    Config::set('inbound-email.domain', 'relaticle.email');
    Storage::fake('local');

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->address = resolve(WorkspaceInboundAddressService::class)->createFor($this->workspace);
});

function storeInboundFixture(WorkspaceInboundAddress $address, User $member, string $rawEml): ?Email
{
    $disk = 'local';
    $path = 'inbound-email/raw/test.eml';
    Storage::disk($disk)->put($path, $rawEml);

    return resolve(StoreInboundEmailAction::class)->execute(
        $address,
        strtolower($member->email),
        $disk,
        $path,
    );
}

it('stores a forwarded email from the workspace owner who is not on the membership pivot', function (): void {
    $this->workspace->users()->detach($this->user->getKey());

    expect($this->user->workspaces()->count())->toBe(0)
        ->and($this->user->ownsWorkspace($this->workspace))->toBeTrue();

    $raw = <<<EML
From: Customer <customer@acme.test>
To: {$this->address->email}
Subject: Owner forward
Message-ID: <inbound-owner-pivot-001@example.com>
Content-Type: text/plain; charset=UTF-8

Body
EML;

    $email = storeInboundFixture($this->address, $this->user, $raw);

    expect($email)->toBeInstanceOf(Email::class);
});

it('stores a forwarded email from a workspace member and links external participants', function (): void {
    $external = 'customer@acme.test';
    People::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'email' => $external,
    ]);

    $raw = <<<EML
From: Customer <{$external}>
To: {$this->address->email}
Subject: Proposal follow-up
Message-ID: <inbound-demo-001@example.com>
Date: Tue, 15 Sep 2026 10:00:00 +0000
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Please review the proposal.
EML;

    $email = storeInboundFixture($this->address, $this->user, $raw);

    expect($email)->toBeInstanceOf(Email::class)
        ->and($email->creation_source)->toBe(EmailCreationSource::BCC_INBOUND)
        ->and($email->connected_account_id)->toBeNull()
        ->and($email->user_id)->toBe($this->user->getKey())
        ->and($email->people)->toHaveCount(1);

    expect(Email::query()->where('rfc_message_id', '<inbound-demo-001@example.com>')->count())->toBe(1);
});

it('ignores inbound mail when the envelope sender is not a workspace member', function (): void {
    $raw = <<<EML
From: Stranger <stranger@example.com>
To: {$this->address->email}
Subject: Spam
Message-ID: <inbound-demo-002@example.com>
Content-Type: text/plain; charset=UTF-8

Hello
EML;

    $disk = 'local';
    $path = 'inbound-email/raw/stranger.eml';
    Storage::disk($disk)->put($path, $raw);

    $email = resolve(StoreInboundEmailAction::class)->execute(
        $this->address,
        'stranger@example.com',
        $disk,
        $path,
    );

    expect($email)->toBeNull();
    expect(Email::query()->count())->toBe(0);
});

it('persists non-inline attachments from forwarded mail', function (): void {
    $raw = <<<EML
From: Lead <lead@example.com>
To: {$this->address->email}
Subject: With file
Message-ID: <inbound-demo-003@example.com>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="bound"

--bound
Content-Type: text/plain; charset=UTF-8

See attached.
--bound
Content-Type: application/pdf; name="proposal.pdf"
Content-Disposition: attachment; filename="proposal.pdf"
Content-Transfer-Encoding: base64

JVBERi0xLjQK
--bound--
EML;

    $email = storeInboundFixture($this->address, $this->user, $raw);

    expect($email)->not->toBeNull()
        ->and($email->has_attachments)->toBeTrue()
        ->and($email->attachments)->toHaveCount(1);

    $attachment = $email->attachments->first();
    expect($attachment->filename)->toBe('proposal.pdf')
        ->and(Storage::disk(EmailAttachment::DISK)->exists((string) $attachment->storage_path))->toBeTrue();
});
