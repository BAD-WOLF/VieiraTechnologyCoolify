@php
    $percent = $item['percent'];
    $width = is_null($percent) ? 0 : min(100, max(0, $percent));
@endphp
<div class="flex flex-col gap-1">
    <div class="flex justify-between text-sm">
        <span class="dark:text-neutral-300">{{ $label }}</span>
        <span class="dark:text-white">
            {{ $item['used'] }} / {{ $item['limit'] > 0 ? $item['limit'] : '∞' }} {{ $unit ?? '' }}
            @if (!is_null($percent))
                <span class="text-xs dark:text-neutral-400">({{ $percent }}%)</span>
            @endif
        </span>
    </div>
    <div class="w-full h-2 rounded-sm bg-black/10 dark:bg-white/10">
        <div @class([
            'h-2 rounded-sm',
            'bg-success' => $width < 80,
            'bg-warning' => $width >= 80 && $width < 100,
            'bg-error' => $width >= 100,
        ]) style="width: {{ $width }}%"></div>
    </div>
</div>
