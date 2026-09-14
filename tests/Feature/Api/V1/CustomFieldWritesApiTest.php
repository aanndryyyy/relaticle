<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\MediaCollection;
use App\Http\Requests\Api\V1\BaseCrmEntityRequest;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldSection;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Rules\OwnedUpload;
use App\Support\Media\MediaLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

mutates(BaseCrmEntityRequest::class, OwnedUpload::class, MediaLookup::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    $this->status = CustomField::query()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();
    Sanctum::actingAs($this->user, ['*']);
});

it('stores a task with a select value given as a label', function (): void {
    $this->postJson('/api/v1/tasks', ['title' => 'Rest label', 'custom_fields' => ['status' => 'Done']])
        ->assertCreated()
        ->assertJsonPath('data.attributes.custom_fields.status.label', 'Done');
});

it('returns 422 with the field key for an unknown label', function (): void {
    $this->postJson('/api/v1/tasks', ['title' => 'Rest bad', 'custom_fields' => ['status' => 'Blocked']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_fields.status']);
});

it('updates a task select value by label', function (): void {
    $task = Task::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->patchJson("/api/v1/tasks/{$task->getKey()}", ['custom_fields' => ['status' => 'in progress']])
        ->assertOk()
        ->assertJsonPath('data.attributes.custom_fields.status.label', 'In progress');
});

it('stores markdown note bodies as html', function (): void {
    $this->postJson('/api/v1/notes', ['title' => 'Md note', 'custom_fields' => ['body' => '**bold**']])
        ->assertCreated()
        ->assertJsonPath('data.attributes.custom_fields.body', fn (string $body): bool => str_contains($body, '<strong>bold</strong>'));
});

it('returns a record field as id and name pairs for an own-workspace company', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $company = Company::factory()->create(['workspace_id' => $this->workspace->getKey(), 'name' => 'Globex']);
    $task = Task::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $task->saveCustomFieldValue($field, [$company->getKey()]);

    $this->getJson("/api/v1/tasks/{$task->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.attributes.custom_fields.related_company.0.id', $company->getKey())
        ->assertJsonPath('data.attributes.custom_fields.related_company.0.name', 'Globex');
});

it('accepts an option label on create and update for every CRM endpoint', function (string $entityType, string $endpoint, string $titleKey): void {
    $section = CustomFieldSection::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => $entityType,
        'name' => 'Agent writes',
        'code' => 'agent_writes',
        'type' => 'section',
        'sort_order' => 97,
        'active' => true,
    ]);

    $field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => $entityType,
        'code' => 'tier',
        'name' => 'Tier',
        'type' => 'select',
        'sort_order' => 97,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    $gold = CustomFieldOption::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_id' => $field->getKey(),
        'name' => 'Gold',
        'sort_order' => 1,
    ]);
    CustomFieldOption::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_id' => $field->getKey(),
        'name' => 'Silver',
        'sort_order' => 2,
    ]);

    $created = $this->postJson("/api/v1/{$endpoint}", [
        $titleKey => 'Agent write probe',
        'custom_fields' => ['tier' => 'gold'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.custom_fields.tier.id', (string) $gold->getKey())
        ->assertJsonPath('data.attributes.custom_fields.tier.label', 'Gold');

    $this->patchJson("/api/v1/{$endpoint}/{$created->json('data.id')}", [
        'custom_fields' => ['tier' => 'Silver'],
    ])
        ->assertOk()
        ->assertJsonPath('data.attributes.custom_fields.tier.label', 'Silver');
})->with([
    'companies' => ['company', 'companies', 'name'],
    'people' => ['people', 'people', 'name'],
    'opportunities' => ['opportunity', 'opportunities', 'name'],
    'tasks' => ['task', 'tasks', 'title'],
    'notes' => ['note', 'notes', 'title'],
]);

it('resolves record names with a constant number of lookups, not one per row', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 92,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    $link = function (int $count) use ($field): void {
        Company::factory()->count($count)->create(['workspace_id' => $this->workspace->getKey()])
            ->each(fn (Company $company) => Task::factory()
                ->create(['workspace_id' => $this->workspace->getKey()])
                ->saveCustomFieldValue($field, [$company->getKey()]));
    };

    $listLookups = function () use (&$response): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/tasks?per_page=50')->assertOk();
        $count = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains($query['query'], 'from "companies"'),
        )->count();
        DB::disableQueryLog();

        return $count;
    };

    $link(3);
    $small = $listLookups();

    $link(9);
    $large = $listLookups();

    $names = collect($response->json('data'))
        ->pluck('attributes.custom_fields.related_company')
        ->filter()
        ->flatten(1)
        ->pluck('name')
        ->filter();

    expect($names)->toHaveCount(12)
        ->and($large)->toBe($small);
});

it('claims file uploads for every CRM entity over rest', function (CrmEntity $entity, string $endpoint, string $titleKey): void {
    Storage::fake('local');
    $field = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => $entity->value,
        'code' => 'contract',
        'name' => 'Contract',
        'type' => 'file-upload',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $pending = $this->workspace->addMediaFromString(pdfBytes())
        ->usingFileName("{$entity->value}.pdf")
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);

    $response = $this->postJson("/api/v1/{$endpoint}", [
        $titleKey => "File {$entity->value}",
        'custom_fields' => [$field->code => $pending->uuid],
    ])->assertCreated();

    $modelClass = $entity->model();
    $record = $modelClass::query()->findOrFail($response->json('data.id'));

    expect($pending->refresh()->model_type)->toBe($record->getMorphClass())
        ->and($pending->model_id)->toBe($record->getKey())
        ->and($pending->collection_name)->toBe(MediaCollection::Attachments->value)
        ->and($pending->custom_field_id)->toBe($field->getKey());
})->with([
    'company' => [CrmEntity::Company, 'companies', 'name'],
    'people' => [CrmEntity::People, 'people', 'name'],
    'opportunity' => [CrmEntity::Opportunity, 'opportunities', 'name'],
    'task' => [CrmEntity::Task, 'tasks', 'title'],
    'note' => [CrmEntity::Note, 'notes', 'title'],
]);

describe('file-upload values over rest', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');
        $this->contract = CustomField::factory()->create([
            'tenant_id' => $this->workspace->getKey(),
            'entity_type' => 'note',
            'code' => 'contract',
            'name' => 'Contract',
            'type' => 'file-upload',
            'validation_rules' => [],
            'active' => true,
            'system_defined' => false,
        ]);
        $this->pending = $this->workspace->addMediaFromString(pdfBytes())->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
            ->usingName('Contract.pdf')
            ->withAttributes(['workspace_id' => $this->workspace->getKey()])
            ->toMediaCollection(MediaCollection::PendingUploads->value);
    });

    it('stores an owned pending file id and returns id, name and url', function (): void {
        $response = $this->postJson('/api/v1/notes', ['title' => 'Rest file', 'custom_fields' => ['contract' => $this->pending->uuid]])
            ->assertCreated()
            ->assertJsonPath('data.attributes.custom_fields.contract.id', $this->pending->uuid)
            ->assertJsonPath('data.attributes.custom_fields.contract.name', 'Contract.pdf');

        expect($response->json('data.attributes.custom_fields.contract.url'))->toContain('/media/'.$this->pending->uuid);
    });

    it('returns 422 for a file id this workspace does not own', function (string $value): void {
        $this->postJson('/api/v1/notes', ['title' => 'Rest bad', 'custom_fields' => ['contract' => $value]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_fields.contract']);
    })->with([
        'unknown uuid' => '00000000-0000-0000-0000-000000000000',
        'path' => 'uploads/00000000-0000-0000-0000-000000000000/x.pdf',
        'traversal' => '../.env',
    ]);

    it('returns null for an empty file field', function (): void {
        $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
        $note->saveCustomFieldValue($this->contract, null);

        $this->getJson("/api/v1/notes/{$note->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.attributes.custom_fields.contract', null);
    });

    it('resolves file urls with a constant number of media lookups', function (): void {
        $attach = function (int $count): void {
            Note::factory()->count($count)->create(['workspace_id' => $this->workspace->getKey()])
                ->each(function (Note $note): void {
                    $media = $this->workspace->addMediaFromString(pdfBytes())
                        ->usingFileName("{$note->getKey()}.pdf")
                        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
                        ->toMediaCollection(MediaCollection::PendingUploads->value);
                    $note->saveCustomFieldValue($this->contract, $media->uuid);
                });
        };

        $lookupCount = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/notes?per_page=50')->assertOk();
            $count = collect(DB::getQueryLog())->filter(
                fn (array $query): bool => str_contains($query['query'], 'from "media"'),
            )->count();
            DB::disableQueryLog();

            return $count;
        };

        $attach(3);
        $small = $lookupCount();

        $attach(9);
        $large = $lookupCount();

        expect($small)->toBeGreaterThan(0)
            ->and($large)->toBe($small);
    });
});

describe('rich editor images over rest', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');
        $this->body = CustomField::query()
            ->where('tenant_id', $this->workspace->getKey())
            ->where('entity_type', 'note')
            ->where('code', 'body')
            ->firstOrFail();
    });

    it('rewrites image sources from the media row on read', function (): void {
        $media = $this->workspace->addMediaFromString(onePixelPng())->usingFileName('a.png')
            ->withAttributes(['workspace_id' => $this->workspace->getKey()])
            ->toMediaCollection(MediaCollection::PendingUploads->value);

        $id = $this->postJson('/api/v1/notes', ['title' => 'Rest image', 'custom_fields' => ['body' => "<p><img src=\"stale\" alt=\"a\" data-id=\"{$media->uuid}\"></p>"]])
            ->assertCreated()
            ->json('data.id');

        $this->getJson("/api/v1/notes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.attributes.custom_fields.body', '<p><img src="'.e($media->refresh()->getUrl()).'" alt="a" data-id="'.$media->uuid.'"></p>');
    });

    it('lists notes with images through one media query per workspace', function (): void {
        foreach (range(1, 3) as $index) {
            $media = $this->workspace->addMediaFromString(onePixelPng())->usingFileName("{$index}.png")
                ->withAttributes(['workspace_id' => $this->workspace->getKey()])
                ->toMediaCollection(MediaCollection::PendingUploads->value);
            $this->postJson('/api/v1/notes', ['title' => "Listed {$index}", 'custom_fields' => ['body' => "<p><img src=\"stale\" data-id=\"{$media->uuid}\"></p>"]])
                ->assertCreated();
        }

        app()->forgetScopedInstances();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson('/api/v1/notes')->assertOk();

        $mediaQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "media"'));

        expect($mediaQueries)->toHaveCount(1)
            ->and($response->json('data'))->toHaveCount(3)
            ->and(collect($response->json('data'))->pluck('attributes.custom_fields.body')->implode(''))->not->toContain('stale');
    });
});
