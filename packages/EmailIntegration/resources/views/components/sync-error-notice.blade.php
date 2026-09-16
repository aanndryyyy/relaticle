@props([
    'account',
])

@if (filled($account->last_error) && ! $account->showsMailboxHistoryImportFailureSummary())
    <div
        role="alert"
        {{ $attributes->class('flex flex-col gap-3 rounded-lg bg-warning-50 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between dark:bg-warning-400/10') }}
    >
        <div class="flex min-w-0 items-start gap-2">
            <x-filament::icon
                icon="heroicon-m-exclamation-triangle"
                class="mt-0.5 h-5 w-5 shrink-0 text-warning-600 dark:text-warning-400"
            />

            <div class="min-w-0">
                <p class="text-sm font-medium text-warning-800 dark:text-warning-300">
                    {{ __('filament/pages/email-accounts.sync_error.heading') }}
                </p>
                <p class="mt-0.5 text-xs text-warning-700 dark:text-warning-400/80">
                    {{ $account->last_error }}
                </p>
            </div>
        </div>

        <div class="shrink-0 sm:pl-2">
            {{ $slot }}
        </div>
    </div>
@endif
