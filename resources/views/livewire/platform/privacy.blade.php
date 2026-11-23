<div>
    <x-slot:title>
        {{ __('Privacy and data') }} | {{ config('app.name') }}
    </x-slot>
    <h1>{{ __('Privacy and data') }}</h1>
    <div class="text-sm dark:text-neutral-400">
        {{ __('Your rights over your personal data (LGPD, Lei nº 13.709/2018), free of charge.') }}
        <a class="underline dark:text-white" href="{{ route('platform.legal.privacy') }}" target="_blank">{{ __('Read the privacy policy') }}</a>
    </div>

    <div class="flex flex-col gap-8 pt-6">
        <section class="flex flex-col gap-2">
            <h3>{{ __('Your data') }}</h3>
            <div class="text-xs dark:text-neutral-400">{{ __('What we keep about you, right now (art. 18, I and II).') }}</div>
            <div class="grid grid-cols-1 gap-1 text-sm md:grid-cols-2">
                @foreach ($summary as $row)
                    <div wire:key="summary-{{ $loop->index }}" class="flex gap-2">
                        <span class="dark:text-neutral-400">{{ $row['label'] }}:</span>
                        <span class="dark:text-white">{{ $row['value'] }}</span>
                    </div>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-2 pt-2">
                <a class="button" href="{{ route('platform.privacy.export') }}">{{ __('Download a complete copy (JSON)') }}</a>
            </div>
            <div class="text-xs dark:text-neutral-400">
                {{ __('The copy has your account, teams, payer data, invoices, consents, requests and access records, in a format other services can read (portability, art. 18, V).') }}
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h3>{{ __('Correct your data') }}</h3>
            <div class="text-sm dark:text-neutral-400">
                {{ __('Fix incomplete, wrong or outdated data yourself (art. 18, III):') }}
                <a class="underline dark:text-white" href="{{ route('profile') }}">{{ __('name, e-mail and password') }}</a> ·
                <a class="underline dark:text-white" href="{{ route('subscription.show') }}">{{ __('payer data') }}</a>.
                {{ __('For anything else, open a request below.') }}
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h3>{{ __('Promotions and news') }}</h3>
            <label class="flex items-start gap-2 text-sm dark:text-white">
                <input type="checkbox" wire:model.live="marketing" class="mt-1">
                <span>{{ __('I want to receive promotions and news of the platform.') }}</span>
            </label>
            <div class="text-xs dark:text-neutral-400">
                {{ __('Optional: if you do not agree, nothing changes in the service; you only do not receive offers and news. You can change it at any time (art. 18, VIII and IX). Notices about your account, invoices, security and maintenance always arrive.') }}
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h3>{{ __('With whom we share your data') }}</h3>
            <div class="text-xs dark:text-neutral-400">{{ __('Only who processes the data for us (art. 18, VII). We do not sell data.') }}</div>
            <ul class="pl-5 text-sm list-disc dark:text-neutral-300">
                @foreach ($processors as $processor)
                    <li wire:key="processor-{{ $loop->index }}"><span class="dark:text-white">{{ $processor['name'] }}</span>: {{ $processor['purpose'] }} ({{ $processor['data'] }})</li>
                @endforeach
            </ul>
        </section>

        <section class="flex flex-col gap-2">
            <h3>{{ __('Requests') }}</h3>
            <div class="text-xs dark:text-neutral-400">
                {{ __('Ask anything about your data, including the review of a decision taken automatically (art. 20). The answer arrives by e-mail in up to 15 days.') }}
            </div>
            <form wire:submit="openRequest" class="flex flex-col gap-2 max-w-2xl">
                <x-forms.select id="requestType" label="{{ __('Request') }}">
                    @foreach ($types as $type)
                        <option value="{{ $type }}">{{ \App\Models\PrivacyRequest::typeLabel($type) }} ({{ \App\Models\PrivacyRequest::typeArticle($type) }})</option>
                    @endforeach
                </x-forms.select>
                <x-forms.textarea id="requestMessage" label="{{ __('Details') }}" rows="3" placeholder="{{ __('What do you need? For a review, say which decision.') }}" />
                <div><x-forms.button type="submit">{{ __('Send request') }}</x-forms.button></div>
            </form>
            @if ($requests->isNotEmpty())
                <div class="flex flex-col gap-2 pt-2">
                    @foreach ($requests as $request)
                        <div wire:key="request-{{ $request->id }}" class="p-3 text-sm border rounded-sm dark:border-coolgray-300">
                            <div class="flex flex-wrap gap-2 dark:text-white">
                                <span>{{ \App\Models\PrivacyRequest::typeLabel($request->type) }}</span>
                                <span class="dark:text-neutral-400">· {{ $request->created_at->format('d/m/Y') }}</span>
                                <span @class(['text-success' => ! $request->isOpen(), 'text-warning' => $request->isOpen()])>
                                    · {{ $request->isOpen() ? __('Answer by :date', ['date' => $request->due_at->format('d/m/Y')]) : __('Answered on :date', ['date' => $request->answered_at?->format('d/m/Y')]) }}
                                </span>
                            </div>
                            @if ($request->message)
                                <div class="pt-1 whitespace-pre-line dark:text-neutral-400">{{ $request->message }}</div>
                            @endif
                            @if ($request->answer)
                                <div class="pt-2 whitespace-pre-line dark:text-neutral-200">{{ $request->answer }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="flex flex-col gap-2">
            <h3>{{ __('Delete my account') }}</h3>
            <div class="text-sm dark:text-neutral-400">
                {{ __('Deletes your profile, the teams only you are in, with their applications, databases, services, volumes and backups, your API tokens and your choices (art. 18, VI). It can not be undone.') }}
            </div>
            <ul class="pl-5 text-xs list-disc dark:text-neutral-400">
                @if (count($preview['close']) > 0)
                    <li>{{ trans_choice('{1} :count team is closed, with :workloads resources.|[2,*] :count teams are closed, with :workloads resources.', count($preview['close']), ['count' => count($preview['close']), 'workloads' => $preview['workloads']]) }}</li>
                @endif
                @if ($preview['payment_customers'] > 0)
                    <li>{{ __('At Stripe, the subscription is cancelled and your customer record is deleted, so the card is not charged again.') }}</li>
                @endif
                @if (count($preview['leave']) + count($preview['transfer']) > 0)
                    <li>{{ __('The teams you share with other people stay with them.') }}</li>
                @endif
                <li>{{ __('Kept, as the law requires: your invoices for :years years (tax law) and the records of your accesses for :months months (Marco Civil da Internet).', ['years' => $fiscalYears, 'months' => $auditMonths]) }}</li>
            </ul>
            @if ($preview['blockers'] !== [])
                @foreach ($preview['blockers'] as $blocker)
                    <div class="text-sm text-warning">{{ $blocker }}</div>
                @endforeach
            @else
                <form wire:submit="deleteAccount" class="flex flex-col gap-2 max-w-md">
                    <x-forms.input id="deletePassword" type="password" label="{{ __('Your password') }}" required />
                    <x-forms.input id="deleteConfirmation" label="{{ __('Type :word to confirm', ['word' => __('DELETE')]) }}" required />
                    <div>
                        <x-forms.button isError type="submit" wire:confirm="{{ __('Delete your account and everything in it now?') }}">{{ __('Delete my account') }}</x-forms.button>
                    </div>
                </form>
            @endif
        </section>

        <section class="flex flex-col gap-1 text-xs dark:text-neutral-400">
            @if ($officer['name'] || $contact)
                <div>
                    {{ __('Data protection contact:') }}
                    @if ($officer['name']){{ $officer['name'] }} · @endif
                    @if ($contact)<a class="underline dark:text-white" href="mailto:{{ $contact }}">{{ $contact }}</a>@endif
                </div>
            @endif
            <div>
                {{ __('If you are not satisfied, you can petition the ANPD (Agência Nacional de Proteção de Dados) and the consumer protection bodies (art. 18, §§ 1º and 8º).') }}
                <a class="underline dark:text-white" href="https://www.gov.br/anpd" target="_blank" rel="noopener">gov.br/anpd</a>
            </div>
        </section>
    </div>
</div>
