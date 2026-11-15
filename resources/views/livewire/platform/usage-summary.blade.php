<section>
    <div class="flex items-center gap-2 pb-2">
        <h3>{{ __('Plan & usage') }}</h3>
        @if ($tenant)
            <a class="text-xs underline dark:text-neutral-400" href="{{ route('subscription.show') }}">{{ __('Details') }}</a>
        @endif
    </div>
    @if ($tenant && $tenant->hasPlan())
        <div class="grid grid-cols-1 gap-4 p-4 border rounded-sm xl:grid-cols-2 dark:border-coolgray-300">
            <div class="text-sm dark:text-neutral-300 xl:col-span-2">
                {{ $tenant->planName() }} · {{ __(str($tenant->status)->replace('_', ' ')->title()->value()) }}
                · {{ __('Region') }}: {{ $tenant->region?->name ?? '—' }}
            </div>
            @include('livewire.platform.partials.usage-bar', ['label' => __('CPU (reserved)'), 'item' => $snapshot['cpus'], 'unit' => 'vCPU'])
            @include('livewire.platform.partials.usage-bar', ['label' => __('Memory (reserved)'), 'item' => $snapshot['memory_mb'], 'unit' => 'MB'])
            @include('livewire.platform.partials.usage-bar', ['label' => __('Resources'), 'item' => $snapshot['resources']])
            @include('livewire.platform.partials.usage-bar', ['label' => __('Storage'), 'item' => $snapshot['storage_gb'], 'unit' => 'GB'])
        </div>
    @else
        <div class="flex flex-col gap-1">
            <div class="font-bold dark:text-warning">{{ __('No active plan.') }}</div>
            <div><a class="underline dark:text-white" href="{{ route('subscription.index') }}">{{ __('Choose a plan') }}</a> {{ __('to start deploying.') }}</div>
        </div>
    @endif
</section>
