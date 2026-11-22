<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    <button type="button" x-on:click="open = ! open; if (open && ! $wire.loaded) { $wire.load() }"
        class="relative flex items-center justify-center w-9 h-9 rounded-sm dark:text-neutral-300 hover:bg-neutral-200 dark:hover:bg-coolgray-200"
        aria-label="{{ __('Notifications') }}" title="{{ __('Notifications') }}">
        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        @if ($unread > 0)
            <span class="absolute top-0.5 right-0.5 min-w-4 h-4 px-1 text-[10px] font-bold leading-4 text-center text-white bg-red-600 rounded-full" data-unread="{{ $unread }}">{{ $unread > 9 ? '9+' : $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.origin.top.right x-on:click.outside="open = false"
        class="absolute right-0 z-50 mt-2 overflow-hidden bg-white border rounded-sm shadow-lg w-80 sm:w-96 dark:bg-coolgray-100 dark:border-coolgray-300">
        <div class="flex items-center justify-between gap-2 px-3 py-2 border-b dark:border-coolgray-300">
            <span class="font-bold dark:text-white">{{ __('Notifications') }}</span>
            @if ($unread > 0)
                <button type="button" wire:click="markAllRead" class="text-xs underline dark:text-neutral-300">{{ __('Mark all as read') }}</button>
            @endif
        </div>
        <div class="overflow-y-auto max-h-[70vh]">
            @if (! $loaded)
                <div class="p-4 text-sm dark:text-neutral-400">{{ __('Loading...') }}</div>
            @endif
            @if ($loaded)
                @forelse ($notifications as $notification)
                    @php
                        $color = match ($notification->kind) {
                            'promotion' => 'bg-success/20 text-success',
                            'alert', 'maintenance' => 'bg-warning/20 text-warning',
                            'billing' => 'bg-blue-500/20 text-blue-500 dark:text-blue-300',
                            default => 'bg-neutral-500/20 dark:text-neutral-300',
                        };
                    @endphp
                    <div wire:key="bell-{{ $notification->uuid }}" x-data="{ expanded: false }"
                        @class(['flex flex-col gap-1 px-3 py-2 border-b dark:border-coolgray-200', 'bg-coollabs/5 dark:bg-coollabs/10' => $notification->unread])>
                        <div class="flex items-center justify-between gap-2">
                            <span class="px-1.5 py-0.5 text-[10px] font-bold uppercase rounded-sm {{ $color }}">{{ \App\Models\PlatformNotification::kindLabel($notification->kind) }}</span>
                            <span class="text-[11px] dark:text-neutral-400" title="{{ $notification->published_at->format('d/m/Y H:i') }}">{{ $notification->published_at->diffForHumans() }}</span>
                        </div>
                        <button type="button" class="text-left" x-on:click="expanded = ! expanded" @if ($notification->unread) wire:click="markRead('{{ $notification->uuid }}')" @endif>
                            <span @class(['text-sm dark:text-white', 'font-bold' => $notification->unread])>
                                @if ($notification->unread)<span class="inline-block w-2 h-2 mr-1 align-middle bg-red-600 rounded-full" aria-label="{{ __('Unread') }}"></span>@endif{{ $notification->title }}
                            </span>
                        </button>
                        @if (filled($notification->body))
                            <p class="text-xs whitespace-pre-line break-words dark:text-neutral-300" x-bind:class="expanded ? '' : 'line-clamp-3'">{{ $notification->body }}</p>
                        @endif
                        @if (filled($notification->action_url))
                            <button type="button" wire:click="follow('{{ $notification->uuid }}')" class="self-start text-xs font-bold underline dark:text-white">{{ $notification->action_label ?: __('Open') }}</button>
                        @endif
                    </div>
                @empty
                    <div class="p-4 text-sm dark:text-neutral-400">{{ __('No notifications yet. News, promotions and the events of your account show up here.') }}</div>
                @endforelse
            @endif
        </div>
    </div>
</div>
