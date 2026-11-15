<div>
    <x-slot:title>
        {{ __('Log history') }} | {{ config('app.name') }}
    </x-slot>
    <h1>{{ __('Log history') }}</h1>
    <div class="pb-4 text-sm dark:text-neutral-400">
        {{ __('Logs of your applications, services and databases, kept after redeploys and restarts (up to 30 days; 90 days on Enterprise).') }}
    </div>
    <form wire:submit="search" class="flex flex-wrap items-end gap-2">
        <x-forms.select id="resourceUuid" label="{{ __('Resource') }}" required>
            <option value="">{{ __('Choose a resource') }}</option>
            @foreach ($resources as $uuid => $label)
                <option value="{{ $uuid }}">{{ $label }}</option>
            @endforeach
        </x-forms.select>
        <x-forms.select id="range" label="{{ __('Period') }}">
            @foreach ($ranges as $option)
                <option value="{{ $option }}">{{ __('Last :period', ['period' => $option]) }}</option>
            @endforeach
        </x-forms.select>
        <x-forms.input id="filter" label="{{ __('Text (optional)') }}" placeholder="error" />
        <x-forms.button type="submit">{{ __('Search') }}</x-forms.button>
    </form>
    @if ($error)
        <div class="pt-4 text-sm text-error">{{ $error }}</div>
    @elseif ($searched && count($lines) === 0)
        <div class="pt-4 text-sm dark:text-neutral-400">{{ __('No logs in this period.') }}</div>
    @elseif (count($lines) > 0)
        <div class="pt-4 text-xs dark:text-neutral-400">{{ __(':count lines (most recent at the bottom)', ['count' => count($lines)]) }}</div>
        <div class="p-3 mt-2 overflow-x-auto font-mono text-xs rounded-sm max-h-[70vh] overflow-y-auto dark:bg-coolgray-100 bg-neutral-100 dark:text-white">
            @foreach ($lines as $index => $line)
                <div wire:key="log-{{ $index }}" class="whitespace-pre-wrap break-all"><span class="dark:text-neutral-500 text-neutral-500">{{ $line['time'] }}</span> {{ $line['line'] }}</div>
            @endforeach
        </div>
    @endif
</div>
