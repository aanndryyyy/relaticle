@php
    $agents = [
        ['claude', 'ri-claude-fill', 'text-anthropic', 'Claude'],
        ['chatgpt', 'ri-openai-fill', 'text-gray-900 dark:text-white', 'ChatGPT'],
        ['gemini', 'ri-gemini-fill', 'text-blue-500', 'Gemini CLI'],
        ['custom', 'ri-code-s-slash-line', 'text-gray-500 dark:text-gray-400', 'Custom'],
    ];
    $records = [
        ['people', 'ri-user-line', 'People'],
        ['companies', 'ri-building-2-line', 'Companies'],
        ['deals', 'ri-funds-line', 'Deals'],
        ['tasks', 'ri-checkbox-circle-line', 'Tasks'],
        ['notes', 'ri-file-text-line', 'Notes'],
    ];
    $nodeClass = 'relative z-10 min-w-0 rounded-lg border border-transparent text-gray-600 dark:text-gray-300 data-[active]:border-primary-500 data-[active]:bg-primary-50 data-[active]:text-gray-950 dark:data-[active]:border-primary-400 dark:data-[active]:bg-primary-950 dark:data-[active]:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:focus-visible:outline-primary-400 enabled:cursor-pointer @lg:border-[var(--surface-block-border)] @lg:bg-[var(--surface-block-bg)]';
    $groupClass = 'relative z-10 mx-auto w-full max-w-sm rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)] p-2 @lg:max-w-none @lg:rounded-none @lg:border-0 @lg:bg-transparent @lg:p-0';
    $groupLabelClass = 'mb-2 text-center text-xs font-medium text-gray-600 dark:text-gray-400 @lg:absolute @lg:bottom-full @lg:mb-3 @lg:w-full';
@endphp

<figure data-agent-network aria-label="{{ __('AI agent connections') }}" class="@container relative isolate mt-4 flex flex-1 flex-col justify-center rounded-lg bg-[var(--surface-canvas-bg)] px-2 py-5 sm:p-6">
    <svg class="pointer-events-none absolute inset-0 -z-10 h-full w-full rounded-lg text-gray-300/40 dark:text-gray-700/30" aria-hidden="true">
        <defs>
            <pattern id="agent-network-dots" width="20" height="20" patternUnits="userSpaceOnUse">
                <circle cx="1" cy="1" r="1" fill="currentColor"/>
            </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#agent-network-dots)"/>
    </svg>

    <div data-network-layout class="relative grid grid-cols-1 items-center @lg:grid-cols-[auto_1fr_auto_1fr_auto] @lg:pt-7">
        <svg data-network-lines class="pointer-events-none absolute inset-0 h-full w-full overflow-visible" fill="none" stroke-linecap="round" aria-hidden="true"></svg>

        <div data-network-agents role="group" aria-label="{{ __('Your agents') }}" class="{{ $groupClass }} @lg:w-32">
            <div class="{{ $groupLabelClass }}" aria-hidden="true">{{ __('Your agents') }}</div>
            <div class="grid grid-cols-2 gap-2 @lg:h-64 @lg:grid-cols-1 @lg:content-between">
                @foreach($agents as [$key, $icon, $color, $name])
                    <button type="button" disabled data-network-node="{{ $key }}" data-network-side="agent"
                            data-network-description="{{ $key === 'custom' ? __('Custom integrations through MCP or REST.') : __('Connect :name through MCP.', ['name' => $name]) }}"
                            aria-label="{{ __('Preview :name connection', ['name' => $name]) }}" aria-pressed="false"
                            class="{{ $nodeClass }} flex h-11 items-center justify-center gap-2 px-2 text-xs font-medium @lg:justify-start @lg:px-3">
                        <x-dynamic-component :component="$icon" class="h-4 w-4 shrink-0 {{ $color }}" aria-hidden="true"/>
                        <span>{{ __($name) }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div data-network-hub class="relative z-10 mx-auto my-6 flex w-full max-w-56 items-center justify-center gap-3 rounded-2xl border border-primary-200 bg-[var(--surface-block-bg)] p-4 shadow-sm @lg:col-start-3 @lg:row-start-1 @lg:my-0 @lg:w-36 @lg:flex-col @lg:py-5 dark:border-primary-400/30">
            <div data-network-highlight class="pointer-events-none absolute -inset-px rounded-2xl border border-primary-600 opacity-0 dark:border-primary-400" aria-hidden="true"></div>
            <x-icons.rela class="h-14 w-12 shrink-0 text-primary [stroke-width:2.5] @lg:h-16 @lg:w-14 dark:text-primary-400"/>
            <div class="text-center">
                <div class="font-display text-lg font-semibold tracking-tight text-gray-950 dark:text-white">Relaticle</div>
                <div class="mt-1 whitespace-nowrap text-micro font-medium text-gray-600 dark:text-gray-300">{{ __('MCP + REST API') }}</div>
            </div>
        </div>

        <div data-network-records role="group" aria-label="{{ __('Your CRM') }}" class="{{ $groupClass }} @lg:col-start-5 @lg:row-start-1 @lg:w-32">
            <div class="{{ $groupLabelClass }}" aria-hidden="true">{{ __('Your CRM') }}</div>
            <div class="grid grid-cols-4 gap-1 @3xs:grid-cols-6 @lg:h-64 @lg:grid-cols-1 @lg:content-between @lg:gap-2">
                @foreach($records as [$key, $icon, $name])
                    <button type="button" disabled data-network-node="{{ $key }}" data-network-side="record"
                            data-network-description="{{ __('Your agents can work with :name.', ['name' => __($name)]) }}"
                            aria-label="{{ __('Preview :name connection', ['name' => __($name)]) }}" aria-pressed="false"
                            @class([
                                $nodeClass,
                                'col-span-2 flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-xs font-medium @lg:col-span-1 @lg:h-11 @lg:min-h-0 @lg:flex-row @lg:justify-start @lg:gap-2 @lg:px-2.5',
                                '@3xs:col-start-2 @lg:col-start-auto' => $key === 'tasks',
                                'col-start-2 @3xs:col-start-4 @lg:col-start-auto' => $key === 'notes',
                            ])>
                        <x-dynamic-component :component="$icon" class="h-4 w-4 shrink-0" aria-hidden="true"/>
                        <span>{{ __($name) }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <figcaption data-network-status class="mt-4 flex min-h-8 items-center justify-center text-center text-xs leading-4 text-gray-600 dark:text-gray-400" aria-live="polite" aria-atomic="true">{{ __('Your agents and CRM, connected.') }}</figcaption>
</figure>
