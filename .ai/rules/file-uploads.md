---
paths:
  - 'app/Filament/**'
  - 'app/Livewire/**'
  - 'app/Support/Media/**'
  - 'app/Actions/Upload/**'
  - 'app/Mcp/Tools/**'
  - 'app/Http/Controllers/Media/**'
  - 'app/Rules/StoredUploadPath.php'
  - 'app/Observers/**'
  - 'app/Console/Commands/**'
---

# File uploads

Durable user files go through medialibrary with a named collection on the owning
model (`App\Enums\MediaCollection`). Two exemptions: import CSVs under
`storage/app/imports` (transient) and Jetstream profile photos (framework-owned).

- Every custom-field upload lands in the workspace's `pending-uploads` collection
  first (`App\Actions\Upload\StorePendingUpload`); `logo` collections are
  written directly. `App\Support\Media\UploadClaims` claims a pending row onto
  the record when a saved custom-field value references it. For `file-upload`
  values a file another record or field already owns fails validation at that
  save; a rich-editor image another record owns is left where it is.
- Rich-editor images go through `App\Support\Media\RichContentAttachments`,
  the Filament `FileAttachmentProvider` behind `RichEditorFieldType` and
  `RichContentEntry`. `data-id` is the Media `uuid`; reads (`FormatsCustomFields`,
  the record page) rewrite `src` from the row, and `CustomFieldInput::richText()`
  tags an untagged `<img>` whose URL names an owned upload so the claim finds it.
  A bare-filename `data-id`, or an `<img>` with no `data-id` whose `src` sits
  under `/storage/`, is a legacy Filament upload on the public disk;
  `media:backfill-rich-editor-attachments --force` moves those onto their records.
- The `file-upload` custom field type ships disabled in `config/custom-fields.php`.
  Its media-backed implementation stays registered so enabling it is a config
  change; tests that need the type call `enableFileUploadFieldType()`.
- Never call `Media::move()`. It copies and deletes, changing `uuid` and path.
  Ownership changes are attribute writes on the existing row.
- `logo` collections stay on the public disk. Everything else follows
  `MEDIA_DISK`, default `public`. The `media` disk it can switch to is
  private-only, and the switch is forward-only: a Media row keeps the disk it
  was uploaded to.
- Do not add a `FileUpload::make(` or configure rich editor attachments with
  `fileAttachmentsDisk(` / `fileAttachmentsDirectory(` outside
  `app/Filament/CustomFields/FileUploadComponent.php` and
  `app/Livewire/App/Profile/UpdateProfileInformation.php`.
  `tests/Arch/ConventionsTest.php` fails on it.
