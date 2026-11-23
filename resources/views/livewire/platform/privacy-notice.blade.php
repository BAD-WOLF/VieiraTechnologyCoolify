<div>
    @if ($show)
        <div class="flex flex-col gap-2 px-4 py-3 mb-4 text-sm border rounded-sm border-warning dark:text-white">
            <div>
                {{ __('Please read and accept the privacy policy and the terms of use (version :version).', ['version' => $version]) }}
                @if ($changes)
                    <span class="dark:text-neutral-300">{{ __('What changed: :changes', ['changes' => $changes]) }}</span>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a class="underline" href="{{ route('platform.legal.privacy') }}" target="_blank">{{ __('Privacy policy') }}</a>
                <a class="underline" href="{{ route('platform.legal.terms') }}" target="_blank">{{ __('Terms of use') }}</a>
            </div>
            @if ($askAge)
                <label class="flex items-start gap-2 text-xs dark:text-neutral-300">
                    <input type="checkbox" wire:model="adult" class="mt-0.5">
                    <span>{{ __('I am 18 or older.') }}</span>
                </label>
                @error('adult')
                    <span class="text-xs text-error">{{ $message }}</span>
                @enderror
            @endif
            @if ($askMarketing)
                <label class="flex items-start gap-2 text-xs dark:text-neutral-300">
                    <input type="checkbox" wire:model="marketing" class="mt-0.5">
                    <span>{{ __('I want to receive promotions and news. Optional: the service is the same without it, and you can change it at any time in Privacy and data.') }}</span>
                </label>
            @endif
            <div><x-forms.button wire:click="accept">{{ __('I read and accept') }}</x-forms.button></div>
        </div>
    @endif
</div>
