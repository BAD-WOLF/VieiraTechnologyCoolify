<x-layout-simple>
    <x-slot:title>{{ __('Plans') }} | </x-slot>
    <section class="px-4 py-12 mx-auto max-w-7xl">
        <div class="pb-10 text-center">
            <h1 class="text-4xl font-bold dark:text-white">{{ config('platform.name') }}</h1>
            <p class="pt-2 dark:text-neutral-400">{{ config('platform.branding.description') }}</p>
            <div class="flex justify-center gap-2 pt-6">
                @if ($registrationEnabled)
                    <a class="button" href="{{ route('register') }}">{{ __('Create account') }}</a>
                @endif
                <a class="button" href="{{ route('login') }}">{{ __('Login') }}</a>
            </div>
        </div>
        <div @class([
            'grid grid-cols-1 gap-6',
            'lg:grid-cols-2' => count($plans) === 2 || count($plans) >= 4,
            'lg:grid-cols-3' => count($plans) === 3,
            'xl:grid-cols-4' => count($plans) >= 4,
        ])>
            @foreach ($plans as $plan)
                <div @class([
                    'flex flex-col gap-4 p-6 border rounded-sm dark:border-coolgray-300 border-neutral-200',
                    'ring-2 ring-warning' => $plan['highlighted'],
                ])>
                    <div>
                        @if ($plan['highlighted'])
                            <div class="pb-1 text-xs font-bold uppercase text-warning">{{ __('Recommended') }}</div>
                        @endif
                        <h2 class="text-2xl font-semibold dark:text-white">{{ $plan['name'] }}</h2>
                        <p class="mt-2 text-sm dark:text-neutral-400">{{ __($plan['description']) }}</p>
                    </div>
                    <div class="dark:text-white">
                        @if ($plan['monthly'])
                            <div class="text-3xl font-bold">{{ $plan['monthly'] }} <span class="text-sm font-normal dark:text-neutral-400">/ {{ __('month') }}</span></div>
                            @if ($plan['yearly'])
                                <div class="text-sm dark:text-neutral-400">{{ __('or :price / year', ['price' => $plan['yearly']]) }}</div>
                            @endif
                        @else
                            <div class="text-xl font-bold">{{ __('Custom pricing') }}</div>
                        @endif
                    </div>
                    @include('platform.partials.plan-details', ['plan' => $plan['definition']])
                    <div class="flex-1"></div>
                    @if ($plan['contact_sales'] && $salesUrl)
                        <a class="w-full button" href="{{ $salesUrl }}">{{ __('Talk to sales') }}</a>
                    @elseif ($registrationEnabled)
                        <a class="w-full button" href="{{ route('register') }}">{{ __('Subscribe') }}</a>
                    @endif
                </div>
            @endforeach
        </div>
        <p class="pt-8 text-xs text-center dark:text-neutral-400">
            {{ __('Move the pointer over the info icon of each item to see what it means.') }}
            {{ __('Upgrades are charged proportionally; downgrades apply on the next renewal.') }}
        </p>
        @if ($termsUrl || $privacyUrl)
            <p class="flex justify-center gap-4 pt-2 text-xs">
                @if ($termsUrl)<a class="underline dark:text-white" href="{{ $termsUrl }}">{{ __('Terms of service') }}</a>@endif
                @if ($privacyUrl)<a class="underline dark:text-white" href="{{ $privacyUrl }}">{{ __('Privacy policy') }}</a>@endif
            </p>
        @endif
    </section>
</x-layout-simple>
