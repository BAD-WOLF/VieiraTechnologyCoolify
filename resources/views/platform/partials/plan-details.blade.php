{{-- Limits, capabilities and other items of a plan, each with its explanation (pricing pages). --}}
<ul class="flex flex-col gap-1 text-sm dark:text-neutral-300">
    @foreach (\App\Services\Platform\PlanCatalog::quotaLines($plan) as $line)
        <li class="flex items-center gap-1" wire:key="quota-{{ $line['key'] }}">
            <span><strong class="dark:text-white">{{ $line['value'] }}</strong> {{ $line['label'] }}</span>
            <x-helper :helper="e($line['help'])" />
        </li>
    @endforeach
</ul>
<ul class="flex flex-col gap-1 text-sm">
    @foreach (\App\Services\Platform\PlanCatalog::featureLines($plan) as $line)
        <li @class(['flex items-center gap-1', 'dark:text-white' => $line['included'], 'dark:text-neutral-500 text-neutral-400' => ! $line['included']]) wire:key="feature-{{ $line['key'] }}">
            <span>{{ $line['included'] ? '✓' : '✗' }}</span>
            <span @class(['line-through' => ! $line['included']])>{{ $line['label'] }}</span>
            <x-helper :helper="e($line['included'] ? $line['help'] : $line['help'].' '.__('Not included in this plan.'))" />
        </li>
    @endforeach
    @foreach (\App\Services\Platform\PlanCatalog::extraLines($plan) as $extra)
        <li class="flex items-center gap-1 dark:text-white" wire:key="extra-{{ $loop->index }}">
            <span>✓</span>
            <span>{{ $extra['text'] }}</span>
            @if ($extra['help'] !== '')
                <x-helper :helper="e($extra['help'])" />
            @endif
        </li>
    @endforeach
</ul>
