<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Media\UploadClaims;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Models\CustomField;

final readonly class OwnedUpload implements ValidationRule
{
    public function __construct(
        private string $workspaceId,
        private string $entityType,
        private CustomField $field,
        private string|int|null $entityId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $owned = is_string($value)
            && Str::isUuid($value)
            && resolve(UploadClaims::class)->isClaimable($this->workspaceId, $value, $this->entityType, $this->entityId, (string) $this->field->getKey());

        if (! $owned) {
            $fail(__('validation.custom_field.upload', ['field' => $this->field->name]));
        }
    }
}
