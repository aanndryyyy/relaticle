---
paths:
  - 'app/Filament/**'
  - 'app/Livewire/**'
  - 'app/Support/Media/**'
  - 'app/Actions/Upload/**'
  - 'app/Mcp/Tools/**'
  - 'app/Http/Controllers/Media/**'
  - 'app/Rules/OwnedUpload.php'
  - 'app/Observers/**'
  - 'app/Console/Commands/**'
---

# File uploads

Durable user files go through medialibrary with a named collection on the owning
model (`App\Enums\MediaCollection`). Two exemptions: import CSVs under
`storage/app/imports` (transient) and Jetstream profile photos (framework-owned).

- `media.workspace_id` and `media.custom_field_id` are real columns. Scope every
  media query on them, never on a `custom_properties` JSON path.
- A `file-upload` value is the Media `uuid`. Derive paths and URLs from the row.
- Every custom-field upload lands in the workspace's `pending-uploads` collection
  first (`App\Actions\Upload\StorePendingUpload`); `logo` collections are
  written directly. `App\Support\Media\UploadClaims` claims a pending row into the
  record's `attachments` collection when a saved value references it, and
  releases rows for the same `custom_field_id` the value no longer names. The
  `saving` observer hook refuses a `file-upload` value another record or field
  already owns.
- Rich-editor images go through `App\Support\Media\RichContentAttachments`,
  the Filament `FileAttachmentProvider` behind `RichEditorFieldType` and
  `RichContentEntry`. `data-id` is the Media `uuid`; reads rewrite `src` from the
  row, and `CustomFieldInput::richText()` tags an untagged `<img>` whose URL
  names an owned upload so the claim finds it. A bare-filename `data-id`, or an
  `<img>` with no `data-id` whose `src` sits under `/storage/`, is a legacy
  Filament upload on the public disk; `media:backfill-rich-editor-attachments
  --force` moves those onto their records.
- Never call `Media::move()`. It copies and deletes, changing `uuid` and path.
  Ownership changes are attribute writes on the existing row.
- `logo` collections stay on the public disk. Everything else follows
  `MEDIA_DISK`, default `local`, which must name a disk in
  `config/filesystems.php`. A row keeps the disk it was uploaded to.
- Do not add a `FileUpload::make(` or configure rich editor attachments with
  `fileAttachmentsDisk(` / `fileAttachmentsDirectory(` outside
  `app/Filament/CustomFields/FileUploadComponent.php` and
  `app/Livewire/App/Profile/UpdateProfileInformation.php`.
  `tests/Arch/ConventionsTest.php` fails on it.
