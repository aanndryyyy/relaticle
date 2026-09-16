@props([
    'account',
])

@php
    use Relaticle\EmailIntegration\Data\MailboxHistoryImportSummary;

    $summary = $account->mailboxHistoryImportSummary();
    $visible = $account->showsMailboxHistoryImportFailureSummary() && $summary instanceof MailboxHistoryImportSummary;
    $dismissToken = $visible
        ? $account->history_import_batch_id.':'.$summary->failedJobs
        : '';
@endphp

@if ($visible)
    <div
        x-data="{
            storageKey: 'relaticle.history-import-failure-dismiss',
            accountId: @js((string) $account->getKey()),
            dismissToken: @js($dismissToken),
            hidden: false,
            init() {
                try {
                    const stored = JSON.parse(sessionStorage.getItem(this.storageKey) || '{}')
                    this.hidden = stored[this.accountId] === this.dismissToken
                } catch (e) {
                    this.hidden = false
                }
            },
            dismiss() {
                this.hidden = true
                try {
                    const stored = JSON.parse(sessionStorage.getItem(this.storageKey) || '{}')
                    stored[this.accountId] = this.dismissToken
                    sessionStorage.setItem(this.storageKey, JSON.stringify(stored))
                } catch (e) {}
            },
        }"
        x-show="! hidden"
        x-cloak
        role="alert"
        {{ $attributes->class('mb-6 flex flex-col gap-3 rounded-lg bg-danger-50 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between dark:bg-danger-400/10') }}
    >
        <div class="flex min-w-0 items-start gap-2">
            <x-filament::icon
                icon="heroicon-m-exclamation-circle"
                class="mt-0.5 h-5 w-5 shrink-0 text-danger-600 dark:text-danger-400"
            />

            <div class="min-w-0">
                <p class="text-sm font-medium text-danger-800 dark:text-danger-300">
                    {{ __('filament/pages/email-account-settings.history_import_failure.heading') }}
                </p>
                <p class="mt-0.5 text-xs text-danger-700 dark:text-danger-400/80">
                    {{ __('filament/pages/email-accounts.history_import.failed_jobs', ['count' => number_format($summary->failedJobs)]) }}
                </p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2 sm:pl-2">
            {{ $slot }}

            <button
                type="button"
                class="rounded p-1 text-danger-600 hover:text-danger-800 dark:text-danger-400 dark:hover:text-danger-200"
                x-on:click="dismiss()"
            >
                <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                <span class="sr-only">{{ __('filament/pages/email-account-settings.history_import_failure.dismiss') }}</span>
            </button>
        </div>
    </div>
@endif
