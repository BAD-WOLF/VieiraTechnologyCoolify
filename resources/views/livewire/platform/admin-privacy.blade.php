<div class="flex flex-col gap-8">
    <section class="flex flex-col gap-2">
        <h3>{{ __('Privacy (LGPD)') }}</h3>
        <div class="flex flex-col gap-1 text-sm">
            @if ($missing === [])
                <div class="text-success">✓ {{ __('The privacy policy identifies the controller and its contact.') }}</div>
            @else
                <div class="text-warning">! {{ __('Missing in Settings → Privacy (LGPD): :items.', ['items' => implode(', ', $missing)]) }}</div>
            @endif
            <div @class(['text-warning' => $overdueRequests > 0, 'dark:text-neutral-300' => $overdueRequests === 0])>
                {{ trans_choice('{0} No open request.|{1} :count open request.|[2,*] :count open requests.', $openRequests, ['count' => $openRequests]) }}
                @if ($overdueRequests > 0)
                    {{ trans_choice('{1} :count is late (15 days, art. 19).|[2,*] :count are late (15 days, art. 19).', $overdueRequests, ['count' => $overdueRequests]) }}
                @endif
            </div>
            @foreach ($incidents->filter->pendingCommunication() as $pending)
                <div wire:key="pending-{{ $pending->id }}" class="text-error">
                    ! {{ __('Incident ":title": communicate the ANPD and the data subjects by :date.', ['title' => $pending->title, 'date' => $pending->communicationDeadline()->setTimezone($timezone)->format('d/m/Y')]) }}
                </div>
            @endforeach
            <div class="dark:text-neutral-400">
                <a class="underline dark:text-white" href="{{ route('platform.legal.privacy') }}" target="_blank">{{ __('Privacy policy') }}</a>
                · <a class="underline dark:text-white" href="{{ route('platform.legal.terms') }}" target="_blank">{{ __('Terms of use') }}</a>
                · {{ __('Version :version', ['version' => $version]) }}@if ($publishedAt) ({{ $publishedAt->setTimezone($timezone)->format('d/m/Y') }})@endif
                · {{ __('Controller, officer, hosting and retention periods: Settings → Privacy (LGPD).') }}
            </div>
        </div>
    </section>

    <section class="flex flex-col gap-2">
        <h3>{{ __('Requests of the data subjects') }}</h3>
        @forelse ($requests as $request)
            <div wire:key="privacy-request-{{ $request->id }}" class="p-3 text-sm border rounded-sm dark:border-coolgray-300">
                <div class="flex flex-wrap gap-2 dark:text-white">
                    <span>{{ \App\Models\PrivacyRequest::typeLabel($request->type) }} ({{ \App\Models\PrivacyRequest::typeArticle($request->type) }})</span>
                    <span class="dark:text-neutral-400">· {{ $request->name }} &lt;{{ $request->email }}&gt; · {{ $request->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}</span>
                    @if ($request->isOpen())
                        <span @class(['text-error' => $request->isOverdue(), 'text-warning' => ! $request->isOverdue()])>· {{ __('Answer by :date', ['date' => $request->due_at->setTimezone($timezone)->format('d/m/Y')]) }}</span>
                    @else
                        <span class="text-success">· {{ __('Answered on :date', ['date' => $request->answered_at?->setTimezone($timezone)->format('d/m/Y')]) }}</span>
                    @endif
                </div>
                @if ($request->message)
                    <div class="pt-1 whitespace-pre-line dark:text-neutral-300">{{ $request->message }}</div>
                @endif
                @if ($request->answer)
                    <div class="pt-2 whitespace-pre-line dark:text-neutral-400">{{ $request->answer }}</div>
                @endif
                @if ($canEdit && $request->isOpen())
                    @if ($answeringId === $request->id)
                        <form wire:submit="sendAnswer" class="flex flex-col gap-2 pt-2">
                            <x-forms.textarea id="answer" label="{{ __('Your answer') }}" rows="4" required
                                helper="{{ __('It goes by e-mail to the data subject and stays on the Privacy and data page. For a deletion, delete the account below.') }}" />
                            <div class="flex gap-2">
                                <x-forms.button type="submit">{{ __('Send answer') }}</x-forms.button>
                                <x-forms.button wire:click="$set('answeringId', null)">{{ __('Cancel') }}</x-forms.button>
                            </div>
                        </form>
                    @else
                        <div class="pt-2"><x-forms.button wire:click="startAnswer({{ $request->id }})">{{ __('Answer') }}</x-forms.button></div>
                    @endif
                @endif
            </div>
        @empty
            <div class="text-sm dark:text-neutral-400">{{ __('No request yet.') }}</div>
        @endforelse
    </section>

    <section class="flex flex-col gap-2">
        <h3>{{ __('Delete an account') }}</h3>
        <div class="text-xs dark:text-neutral-400">
            {{ __('Closes the teams only this user is in (applications, databases and services are removed from the nodes and open invoices cancelled), keeps what the law requires and e-mails the user.') }}
        </div>
        @if ($canEdit)
            <form wire:submit="deleteAccount" class="flex flex-wrap items-end gap-2">
                <x-forms.input id="deleteEmail" type="email" label="{{ __('E-mail of the account') }}" required />
                <x-forms.input id="deleteReason" label="{{ __('Reason') }}" required placeholder="{{ __('Request of the user, violation of the terms...') }}" />
                <x-forms.button isError type="submit" wire:confirm="{{ __('Delete this account and everything only it has? It can not be undone.') }}">{{ __('Delete account') }}</x-forms.button>
            </form>
        @endif
        @if ($deletions->isNotEmpty())
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-left dark:text-neutral-400">
                        <th class="py-1">{{ __('Account') }}</th>
                        <th>{{ __('Asked by') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Kept until') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deletions as $deletion)
                        <tr wire:key="deletion-{{ $deletion->id }}" class="dark:text-neutral-300">
                            <td class="py-1">{{ $deletion->email }}</td>
                            <td>{{ \App\Models\PrivacyDeletedAccount::requestedByLabel($deletion->requested_by) }}@if ($deletion->reason) · {{ $deletion->reason }}@endif</td>
                            <td @class(['text-error' => $deletion->status === 'failed', 'text-success' => $deletion->status === 'done'])>
                                {{ match ($deletion->status) { 'done' => __('Deleted'), 'failed' => __('Failed'), default => __('Deleting') } }}
                                @if ($deletion->error) · {{ $deletion->error }}@endif
                            </td>
                            <td>{{ $deletion->retain_until->setTimezone($timezone)->format('d/m/Y') }}</td>
                            <td>
                                @if ($canEdit && $deletion->status === 'failed')
                                    <x-forms.button wire:click="retryDeletion({{ $deletion->id }})">{{ __('Try again') }}</x-forms.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="flex flex-col gap-2">
        <h3>{{ __('Security incidents') }}</h3>
        <div class="text-xs dark:text-neutral-400">
            {{ __('Record every incident with personal data, even small ones (kept for at least 5 years). When it may cause relevant risk or harm, communicate the ANPD (on its own form, gov.br/anpd) and the data subjects within 3 business days (art. 48; Resolução CD/ANPD nº 15/2024).') }}
        </div>
        @if ($canEdit)
            <form wire:submit="saveIncident" class="flex flex-col gap-2 p-3 border rounded-sm dark:border-coolgray-300">
                <div class="grid grid-cols-1 gap-2 lg:grid-cols-3">
                    <x-forms.input id="incident.title" label="{{ __('Title') }}" required />
                    <x-forms.input id="incident.known_at" type="datetime-local" label="{{ __('When you learned of it') }}" required />
                    <x-forms.input id="incident.occurred_at" type="datetime-local" label="{{ __('When it happened (if known)') }}" />
                </div>
                <x-forms.textarea id="incident.description" label="{{ __('What happened') }}" rows="3" required />
                <x-forms.textarea id="incident.data_categories" label="{{ __('Personal data involved') }}" rows="2" required />
                <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                    <x-forms.input id="incident.affected_count" type="number" min="0" label="{{ __('People affected (estimate)') }}" />
                    <label class="flex items-center gap-2 pt-6 text-sm dark:text-white">
                        <input type="checkbox" wire:model="incident.relevant_risk">
                        {{ __('May cause relevant risk or harm to the data subjects') }}
                    </label>
                </div>
                <x-forms.textarea id="incident.risks" label="{{ __('Risks and possible consequences') }}" rows="2" />
                <x-forms.textarea id="incident.security_measures" label="{{ __('How the data was protected') }}" rows="2" />
                <x-forms.textarea id="incident.mitigation" label="{{ __('What was and will be done') }}" rows="2" />
                <x-forms.textarea id="incident.recommendations" label="{{ __('What the data subjects should do') }}" rows="2" />
                <x-forms.textarea id="incident.delay_reason" label="{{ __('Why the communication is late (if it is)') }}" rows="2" />
                <div class="flex gap-2">
                    <x-forms.button type="submit">{{ $editingIncidentId ? __('Save incident') : __('Record incident') }}</x-forms.button>
                    @if ($editingIncidentId)
                        <x-forms.button wire:click="cancelIncident">{{ __('Cancel') }}</x-forms.button>
                    @endif
                </div>
            </form>
        @endif
        @foreach ($incidents as $item)
            <div wire:key="incident-{{ $item->id }}" class="p-3 text-sm border rounded-sm dark:border-coolgray-300">
                <div class="flex flex-wrap gap-2 dark:text-white">
                    <span>{{ $item->title }}</span>
                    <span class="dark:text-neutral-400">· {{ __('Learned on :date', ['date' => $item->known_at->setTimezone($timezone)->format('d/m/Y H:i')]) }}</span>
                    @if ($item->relevant_risk)
                        <span class="text-warning">· {{ __('Relevant risk') }}</span>
                    @endif
                </div>
                <div class="pt-1 text-xs dark:text-neutral-400">
                    {{ __('ANPD: :status', ['status' => $item->anpd_communicated_at ? $item->anpd_communicated_at->setTimezone($timezone)->format('d/m/Y').($item->anpd_protocol ? ' ('.$item->anpd_protocol.')' : '') : __('not communicated')]) }}
                    · {{ __('Data subjects: :status', ['status' => $item->titulares_communicated_at ? $item->titulares_communicated_at->setTimezone($timezone)->format('d/m/Y').' ('.trans_choice('{1} :count user|[2,*] :count users', (int) $item->titulares_communicated_count, ['count' => (int) $item->titulares_communicated_count]).')' : __('not communicated')]) }}
                    @if ($item->relevant_risk)
                        · {{ __('Deadline: :date', ['date' => $item->communicationDeadline()->setTimezone($timezone)->format('d/m/Y')]) }}
                    @endif
                </div>
                @if ($canEdit)
                    <div class="flex flex-wrap items-end gap-2 pt-2">
                        <x-forms.button wire:click="editIncident({{ $item->id }})">{{ __('Edit') }}</x-forms.button>
                        @if ($communicatingId === $item->id)
                            <x-forms.select id="communicateAudience" label="{{ __('Tell') }}">
                                <option value="all">{{ __('Everyone') }}</option>
                                <option value="customers">{{ __('Customers') }}</option>
                                <option value="operators">{{ __('Team of the platform') }}</option>
                            </x-forms.select>
                            <x-forms.button isError wire:click="communicate({{ $item->id }})" wire:confirm="{{ __('E-mail every chosen user about this incident now?') }}">{{ __('Send the communication') }}</x-forms.button>
                        @else
                            <x-forms.button wire:click="$set('communicatingId', {{ $item->id }})">{{ __('Communicate the data subjects') }}</x-forms.button>
                        @endif
                        @if ($anpdIncidentId === $item->id)
                            <form wire:submit="recordAnpd" class="flex flex-wrap items-end gap-2">
                                <x-forms.input id="anpdDate" type="date" label="{{ __('Date') }}" required />
                                <x-forms.input id="anpdProtocol" label="{{ __('Protocol') }}" />
                                <x-forms.button type="submit">{{ __('Save') }}</x-forms.button>
                            </form>
                        @else
                            <x-forms.button wire:click="$set('anpdIncidentId', {{ $item->id }})">{{ __('Record the communication to the ANPD') }}</x-forms.button>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </section>

    <section class="flex flex-col gap-2">
        <h3>{{ __('New version of the privacy policy') }}</h3>
        <div class="text-xs dark:text-neutral-400">
            {{ __('After changing the privacy settings, publish a version: every user is told what changed and accepts it on the next visit (art. 8º, § 6º).') }}
        </div>
        @if ($canEdit)
            <form wire:submit="publishPolicy" class="flex flex-col gap-2 max-w-2xl">
                <x-forms.textarea id="policyChanges" label="{{ __('What changed') }}" rows="3" required />
                <div><x-forms.button type="submit" wire:confirm="{{ __('Publish a new version and ask every user to accept it?') }}">{{ __('Publish version :version', ['version' => $version + 1]) }}</x-forms.button></div>
            </form>
        @endif
    </section>

    <section class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <h3>{{ __('Record of the processing operations') }}</h3>
            <x-forms.button wire:click="downloadRecord">{{ __('Download (CSV)') }}</x-forms.button>
        </div>
        <div class="text-xs dark:text-neutral-400">{{ __('Kept for the ANPD (art. 37), built from what the platform does and how it is configured.') }}</div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-left dark:text-neutral-400">
                        <th class="py-1 pr-2">{{ __('Operation') }}</th>
                        <th class="pr-2">{{ __('Data') }}</th>
                        <th class="pr-2">{{ __('Purpose') }}</th>
                        <th class="pr-2">{{ __('Legal basis') }}</th>
                        <th class="pr-2">{{ __('Shared with') }}</th>
                        <th>{{ __('Retention') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($operations as $operation)
                        <tr wire:key="operation-{{ $loop->index }}" class="align-top dark:text-neutral-300">
                            <td class="py-1 pr-2 dark:text-white">{{ $operation['operation'] }}</td>
                            <td class="pr-2">{{ $operation['data'] }}</td>
                            <td class="pr-2">{{ $operation['purpose'] }}</td>
                            <td class="pr-2">{{ $operation['basis'] }}</td>
                            <td class="pr-2">{{ $operation['sharing'] }}</td>
                            <td>{{ $operation['retention'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
