<div class="flex flex-col max-w-2xl gap-6 mx-auto pt-10">
    <x-slot:title>
        {{ __('Welcome') }} | {{ config('platform.name') }}
    </x-slot>
    <div>
        <h1>{{ __('Welcome to :name', ['name' => config('platform.name')]) }}</h1>
        <div class="subtitle">{{ __('Your applications run in an isolated network with the resources of your plan. No server setup needed.') }}</div>
    </div>

    <form wire:submit="finish" class="flex flex-col gap-4">
        @if ($regions->count() > 1)
            <x-forms.select id="regionId" label="{{ __('Region') }}" required>
                @foreach ($regions as $region)
                    <option value="{{ $region->id }}">{{ $region->name }} ({{ $region->slug }})</option>
                @endforeach
            </x-forms.select>
        @elseif ($regions->count() === 1)
            <div class="text-sm dark:text-neutral-300">{{ __('Region') }}: <strong class="dark:text-white">{{ $regions->first()->name }}</strong></div>
        @else
            <x-callout type="warning" title="{{ __('No region available') }}">
                {{ __('The platform has no active region yet. Please contact support.') }}
            </x-callout>
        @endif
        <x-forms.input id="projectName" label="{{ __('First project name') }}" required />
        <div class="flex gap-2">
            <x-forms.button type="submit" isHighlighted :disabled="$regions->count() === 0">{{ __('Create and deploy my first app') }}</x-forms.button>
            <x-forms.button wire:click="skip">{{ __('Skip') }}</x-forms.button>
        </div>
    </form>
</div>
