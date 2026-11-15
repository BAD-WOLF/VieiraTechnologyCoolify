<div>
    <x-slot:title>
        {{ __('Integrations') }} | {{ config('app.name') }}
    </x-slot>
    <h1>{{ __('Integrations') }}</h1>
    <div class="flex flex-col gap-8 pt-4">
        <section class="flex flex-col gap-2">
            <h3>{{ __('API') }}</h3>
            <div class="text-sm dark:text-neutral-400">
                {{ __('Create a token in Keys & Tokens → API tokens and use it as "Authorization: Bearer <token>".') }}
                <a class="underline dark:text-white" href="{{ $apiDocsUrl }}" target="_blank" rel="noopener">{{ __('Platform API (OpenAPI)') }}</a>
            </div>
        </section>
        <livewire:platform.webhooks />
        @if ($isTenant)
            <livewire:platform.observability />
        @endif
    </div>
</div>
