{{-- Release of the platform the operator runs (tag platform-vX.Y.Z, "dev" for local builds). --}}
<span {{ $attributes->merge(['class' => 'text-xs opacity-90']) }}>{{ \Illuminate\Support\Str::after((string) config('platform.version'), 'platform-') }}</span>
