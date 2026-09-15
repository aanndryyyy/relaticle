<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\ComposeRecordRecipientResolver;

mutates(EmailsRelationManager::class);
mutates(ComposeRecordRecipientResolver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
    ]));

    $this->person = People::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->user->id,
    ]);
});

it('opens the floating composer instead of a compose modal', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail')
        ->assertDispatched('composer:open');
});

it('prefills the composer to field with the record primary email when compose is opened from a person', function (): void {
    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $this->person->saveCustomFieldValue($emailsField, ['jane@example.com', 'other@example.com'], $this->workspace);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail')
        ->assertDispatched('composer:open', function (string $event, array $params): bool {
            expect($params['payload']['to'])->toBe(['jane@example.com'])
                ->and($params['payload']['linkRecordType'])->toBe(People::class)
                ->and($params['payload']['linkRecordId'])->toBe((string) $this->person->getKey());

            return true;
        });
});

it('leaves the composer to field empty when the person has no email address', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail')
        ->assertDispatched('composer:open', function (string $event, array $params): bool {
            expect($params['payload']['to'])->toBe([]);

            return true;
        });
});

it('opens the composer when the mailbox cannot send', function (): void {
    $this->account->update([
        'capabilities' => [
            'email' => true,
            'send' => false,
            'calendar' => false,
        ],
    ]);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertActionVisible('composeEmail')
        ->callAction('composeEmail')
        ->assertDispatched('composer:open');
});

it('is hidden when user has no active connected account', function (): void {
    $this->account->update(['status' => EmailAccountStatus::DISCONNECTED]);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertActionHidden('composeEmail');
});
