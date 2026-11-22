<div class="flex flex-col gap-6">
    <div>
        <h3>{{ __('Announcements') }}</h3>
        <div class="text-xs dark:text-neutral-400">{{ __('Write to the users of the platform: news, promotions, updates and maintenance show up in the bell at the top right of the dashboard, next to the events of each account.') }}</div>
    </div>

    @if ($canEdit)
        <form wire:submit="publish" class="flex flex-col gap-3">
            <div class="grid grid-cols-1 gap-2 lg:grid-cols-3">
                <x-forms.select id="kind" label="{{ __('Type') }}">
                    @foreach (\App\Models\PlatformNotification::ANNOUNCEMENT_KINDS as $option)
                        <option value="{{ $option }}">{{ \App\Models\PlatformNotification::kindLabel($option) }}</option>
                    @endforeach
                </x-forms.select>
                <x-forms.select id="audience" wire:model.live="audience" label="{{ __('Who receives it') }}"
                    helper="{{ trans_choice('{0} Nobody receives it yet.|{1} It reaches :count user.|[2,*] It reaches :count users.', $reach, ['count' => $reach]) }}">
                    <option value="all">{{ __('Everyone') }}</option>
                    <option value="customers">{{ __('Customers') }}</option>
                    <option value="operators">{{ __('Team of the platform') }}</option>
                    <option value="plan">{{ __('Customers of a plan') }}</option>
                    <option value="team">{{ __('One customer') }}</option>
                </x-forms.select>
                @if ($audience === 'plan')
                    <x-forms.select id="plan" wire:model.live="plan" label="{{ __('Plan') }}">
                        <option value="">{{ __('Choose a plan') }}</option>
                        @foreach ($plans as $slug => $name)
                            <option value="{{ $slug }}">{{ $name }}</option>
                        @endforeach
                    </x-forms.select>
                @elseif ($audience === 'team')
                    <x-forms.input id="teamId" wire:model.live.debounce.400ms="teamId" type="number" label="{{ __('Number of the customer (#)') }}"
                        helper="{{ $teamName ? __('Customer: :name', ['name' => $teamName]) : __('The number is in the Tenants tab, next to the name.') }}" />
                @endif
            </div>
            <x-forms.input id="title" label="{{ __('Title') }}" required />
            <x-forms.textarea id="body" label="{{ __('Message') }}" rows="4" />
            <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                <x-forms.input id="actionUrl" label="{{ __('Link (optional)') }}" placeholder="https://" />
                <x-forms.input id="actionLabel" label="{{ __('Text of the link') }}" placeholder="{{ __('Open') }}" />
                <x-forms.input id="publishAt" type="datetime-local" label="{{ __('Publish at (empty: now)') }}" helper="{{ __('Time zone: :zone', ['zone' => $timezone]) }}" />
                <x-forms.input id="expiresAt" type="datetime-local" label="{{ __('Remove from the bell at (optional)') }}" />
            </div>
            <div><x-forms.button type="submit" isHighlighted>{{ __('Send announcement') }}</x-forms.button></div>
        </form>
    @else
        <x-callout type="info" title="{{ __('Read only') }}">{{ __('Only the owner of the root team can send announcements.') }}</x-callout>
    @endif

    <section class="flex flex-col gap-2">
        <h3>{{ __('Sent') }}</h3>
        @forelse ($announcements as $announcement)
            @php
                $live = $announcement->published_at->isPast() && (is_null($announcement->expires_at) || $announcement->expires_at->isFuture());
            @endphp
            <div wire:key="announcement-{{ $announcement->uuid }}" class="flex flex-col gap-1 p-2 text-sm border rounded-sm dark:border-coolgray-300">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="px-1.5 py-0.5 text-[10px] font-bold uppercase rounded-sm bg-neutral-500/20 dark:text-neutral-300">{{ \App\Models\PlatformNotification::kindLabel($announcement->kind) }}</span>
                    <span class="font-bold dark:text-white">{{ $announcement->title }}</span>
                    <span class="text-xs dark:text-neutral-400">{{ \App\Livewire\Platform\AdminNotifications::audienceLabel($announcement) }}</span>
                    <span class="text-xs dark:text-neutral-400">
                        @if ($announcement->published_at->isFuture())
                            {{ __('Scheduled for :date', ['date' => $announcement->published_at->timezone($timezone)->format('d/m/Y H:i')]) }}
                        @else
                            {{ __('Published on :date', ['date' => $announcement->published_at->timezone($timezone)->format('d/m/Y H:i')]) }}
                        @endif
                        @if ($announcement->expires_at)
                            · {{ $announcement->expires_at->isPast() ? __('Ended on :date', ['date' => $announcement->expires_at->timezone($timezone)->format('d/m/Y H:i')]) : __('Until :date', ['date' => $announcement->expires_at->timezone($timezone)->format('d/m/Y H:i')]) }}
                        @endif
                        · {{ trans_choice('{0} Read by nobody yet|{1} Read by :count user|[2,*] Read by :count users', $announcement->reads_count, ['count' => $announcement->reads_count]) }}
                    </span>
                    @if ($canEdit)
                        <span class="flex gap-2 ml-auto">
                            @if ($live)
                                <button type="button" class="underline dark:text-white" wire:click="end('{{ $announcement->uuid }}')">{{ __('End now') }}</button>
                            @endif
                            <button type="button" class="underline text-error" wire:click="delete('{{ $announcement->uuid }}')" wire:confirm="{{ __('Delete this announcement? It leaves the bell of every user.') }}">{{ __('Delete') }}</button>
                        </span>
                    @endif
                </div>
                @if ($announcement->body)
                    <p class="text-xs whitespace-pre-line dark:text-neutral-300">{{ \Illuminate\Support\Str::limit($announcement->body, 300) }}</p>
                @endif
            </div>
        @empty
            <div class="text-sm dark:text-neutral-400">{{ __('No announcements yet.') }}</div>
        @endforelse
    </section>
</div>
