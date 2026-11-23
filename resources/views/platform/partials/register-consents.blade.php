{{-- Sign-up (LGPD): acceptance of the policy and the terms, age, and the free choice about promotions. --}}
<div class="flex flex-col gap-2 pt-2 text-xs dark:text-neutral-300">
    <label class="flex items-start gap-2">
        <input type="checkbox" name="accept_policy" value="1" required @checked(old('accept_policy')) class="mt-0.5">
        <span>{!! __('I read and accept the :privacy and the :terms.', [
            'privacy' => '<a class="underline" target="_blank" href="'.e(route('platform.legal.privacy')).'">'.e(__('privacy policy')).'</a>',
            'terms' => '<a class="underline" target="_blank" href="'.e(route('platform.legal.terms')).'">'.e(__('terms of use')).'</a>',
        ]) !!}</span>
    </label>
    @error('accept_policy')
        <div class="text-error">{{ $message }}</div>
    @enderror
    <label class="flex items-start gap-2">
        <input type="checkbox" name="adult" value="1" required @checked(old('adult')) class="mt-0.5">
        <span>{{ __('I am 18 or older.') }}</span>
    </label>
    @error('adult')
        <div class="text-error">{{ $message }}</div>
    @enderror
    <label class="flex items-start gap-2">
        <input type="checkbox" name="marketing" value="1" @checked(old('marketing')) class="mt-0.5">
        <span>{{ __('I want to receive promotions and news. Optional: the service is the same without it, and you can change it at any time in Privacy and data.') }}</span>
    </label>
</div>
