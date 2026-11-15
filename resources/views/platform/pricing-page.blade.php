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
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            @foreach ($plans as $plan)
                <div class="flex flex-col gap-4 p-6 border rounded-sm dark:border-coolgray-300 border-neutral-200">
                    <div>
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
                    <ul class="flex flex-col gap-1 text-sm dark:text-neutral-300">
                        <li><strong class="dark:text-white">{{ rtrim(rtrim(number_format($plan['quotas']['cpus'], 2, '.', ''), '0'), '.') }}</strong> vCPU</li>
                        <li><strong class="dark:text-white">{{ round($plan['quotas']['memory_mb'] / 1024, 1) }} GB</strong> {{ __('RAM') }}</li>
                        <li><strong class="dark:text-white">{{ $plan['quotas']['storage_gb'] }} GB</strong> {{ __('storage') }}</li>
                        <li><strong class="dark:text-white">{{ $plan['quotas']['resources'] }}</strong> {{ __('applications, databases or services') }}</li>
                        <li><strong class="dark:text-white">{{ $plan['quotas']['containers'] }}</strong> {{ __('Containers') }}</li>
                        <li><strong class="dark:text-white">{{ $plan['quotas']['egress_gb'] }} GB</strong> {{ __('egress included') }}</li>
                        <li><strong class="dark:text-white">{{ $plan['quotas']['deployments_per_day'] > 0 ? $plan['quotas']['deployments_per_day'] : __('Unlimited') }}</strong> {{ __('deployments per day') }}</li>
                        <li><strong class="dark:text-white">{{ $plan['quotas']['custom_domains'] > 0 ? $plan['quotas']['custom_domains'] : __('Unlimited') }}</strong> {{ __('Custom domains') }}</li>
                    </ul>
                    <ul class="flex flex-col gap-1 text-sm">
                        @foreach ([
                            'persistent_volumes' => __('Persistent volumes'),
                            'scheduled_backups' => __('Scheduled backups'),
                            'log_export' => __('Log export'),
                            'preview_deployments' => __('Preview deployments'),
                            'public_databases' => __('Public databases'),
                            'dedicated_server' => __('Dedicated nodes (VM isolation)'),
                            'sso' => __('SSO (SAML/OIDC)'),
                            'traces' => __('Application traces (OpenTelemetry)'),
                        ] as $feature => $label)
                            <li @class(['flex gap-2', 'dark:text-white' => $plan['features'][$feature] ?? false, 'line-through dark:text-neutral-600 text-neutral-400' => ! ($plan['features'][$feature] ?? false)])>
                                <span>{{ ($plan['features'][$feature] ?? false) ? '✓' : '✗' }}</span>{{ $label }}
                            </li>
                        @endforeach
                    </ul>
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
            {{ __('CPU and memory are hard limits of the plan. Egress and storage above the plan are billed on the next invoice.') }}
            {{ __('Payment with Pix or boleto (Banco Inter). Upgrades are charged proportionally; downgrades apply on the next renewal.') }}
        </p>
        @if ($termsUrl || $privacyUrl)
            <p class="flex justify-center gap-4 pt-2 text-xs">
                @if ($termsUrl)<a class="underline dark:text-white" href="{{ $termsUrl }}">{{ __('Terms of service') }}</a>@endif
                @if ($privacyUrl)<a class="underline dark:text-white" href="{{ $privacyUrl }}">{{ __('Privacy policy') }}</a>@endif
            </p>
        @endif
    </section>
</x-layout-simple>
