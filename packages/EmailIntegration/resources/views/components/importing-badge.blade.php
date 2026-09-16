@props([
    'account',
    'icon',
])

@php
    $percent = $account->syncDisplayPercent();
    $storing = $account->isMailboxHistoryImportStoringPhase();
    $countsLabel = $account->historyImportProcessedLabel();
    $showPercent = $account->showsPercentOnImportBadge();
    $ariaNow = $storing && $countsLabel !== null ? $percent : ($showPercent ? $percent : 0);
@endphp

<x-filament::badge
    color="info"
    size="sm"
    :icon="$icon"
    class="whitespace-nowrap"
    role="progressbar"
    aria-busy="true"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-valuenow="$ariaNow"
    :aria-valuetext="$storing && $countsLabel !== null
        ? $countsLabel
        : __('filament/pages/email-accounts.importing_percent', ['percent' => $percent])"
    :aria-label="$storing
        ? __('filament/pages/email-accounts.finishing_import')
        : __('filament/pages/email-accounts.importing')"
>
    @if ($storing)
        {{ __('filament/pages/email-accounts.finishing_import') }}
        @if ($countsLabel !== null)
            <span class="font-normal opacity-80">· {{ $countsLabel }}</span>
        @endif
    @else
        {{ __('filament/pages/email-accounts.importing') }}
        @if ($showPercent)
            {{ __('filament/pages/email-accounts.importing_percent', ['percent' => $percent]) }}
        @endif
    @endif
</x-filament::badge>
