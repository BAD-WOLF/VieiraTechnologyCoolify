{{-- Termos de uso: escritos em português, a língua dos clientes. --}}
<x-layout-simple>
    <x-slot:title>Termos de Uso | </x-slot>
    <article class="max-w-3xl px-4 py-12 mx-auto text-sm leading-relaxed dark:text-neutral-300">
        <h1 class="text-3xl font-bold dark:text-white">Termos de Uso</h1>
        <p class="pt-1 text-xs dark:text-neutral-400">
            Versão {{ $version }}{{ $publishedAt ? ', publicada em '.$publishedAt->format('d/m/Y') : '' }}.
            Fornecedor: {{ $controller }}{{ $document ? ' ('.$document.')' : '' }}{{ $address ? ', '.$address : '' }}.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">1. O serviço</h2>
        <p class="pt-2">
            A plataforma {{ $platform }} hospeda aplicações, bancos de dados e serviços, com deploy automático, nos
            planos descritos na <a class="underline" href="{{ route('platform.pricing') }}">página de planos</a>.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">2. A conta</h2>
        <p class="pt-2">
            A conta é só para maiores de 18 anos. Você informa dados verdadeiros, mantém a senha em segredo, usa
            a autenticação em dois fatores quando possível e responde pelo que é feito com a sua conta e pelos
            membros que convidar.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">3. Pagamento</h2>
        <p class="pt-2">
            O plano é cobrado por fatura, com os preços da página de planos. O uso acima do incluído no plano é
            cobrado na renovação. Faturas vencidas podem ter multa e juros, como informado em cada fatura.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">4. Bloqueios e suspensão</h2>
        <p class="pt-2">
            Com uma fatura vencida há {{ $suspendDays }} dias, novos deploys ficam bloqueados; há {{ $stopDays }} dias,
            os containers são parados até o pagamento. Ao passar dos limites do plano, não é possível criar novos
            recursos. Essas decisões são automáticas, e você pode pedir a revisão por uma pessoa (LGPD, art. 20) em
            Privacidade e dados. Podemos suspender, depois de avisar quando possível, contas usadas para atividades
            ilegais, ataques, envio de spam, mineração de criptomoedas ou que comprometam outros clientes.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">5. Os seus dados e os dados dos seus usuários</h2>
        <p class="pt-2">
            O tratamento dos seus dados pessoais segue a <a class="underline" href="{{ route('platform.legal.privacy') }}">Política de Privacidade</a>.
            Nas suas aplicações, você é o controlador dos dados dos seus usuários e a plataforma é operadora: ela os
            trata só para executar o serviço, seguindo as suas instruções, mantém o sigilo e as medidas de segurança,
            avisa você de qualquer incidente que os afete e os apaga quando você os apaga ou encerra a conta (LGPD,
            arts. 37 a 40). Cabe a você ter base legal para tratar esses dados e atender os seus titulares.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">6. Encerramento</h2>
        <p class="pt-2">
            Você pode encerrar a conta quando quiser, em Privacidade e dados, depois de pagar as faturas em aberto.
            Ao encerrar, removemos as suas aplicações, bancos de dados, serviços, volumes e backups, e apagamos os
            seus dados, guardando apenas o que a lei exige: as faturas por {{ $fiscalYears }} anos e os registros de
            acesso por {{ $auditMonths }} meses.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">7. Disponibilidade e responsabilidade</h2>
        <p class="pt-2">
            Mantemos a plataforma no ar com o cuidado esperado de um provedor de hospedagem, com backups e
            monitoramento, mas manutenções e falhas podem acontecer. Recomendamos que você mantenha cópias dos seus
            dados. Os direitos do Código de Defesa do Consumidor, quando aplicáveis, ficam preservados.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">8. Mudanças nestes termos</h2>
        <p class="pt-2">
            Quando estes termos mudarem, avisamos no painel, com o que mudou em destaque, e pedimos a sua ciência no
            próximo acesso. Se não concordar, você pode encerrar a conta.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">9. Lei e foro</h2>
        <p class="pt-2">
            Estes termos seguem a lei brasileira. Fica eleito o foro do domicílio do consumidor, quando a relação for
            de consumo.
        </p>

        @if ($contact)
            <p class="pt-6">Contato: <a class="underline" href="mailto:{{ $contact }}">{{ $contact }}</a>.</p>
        @endif
        @if ($termsExtra)
            <h2 class="pt-8 text-xl font-semibold dark:text-white">10. Condições adicionais</h2>
            <p class="pt-2 whitespace-pre-line">{{ $termsExtra }}</p>
        @endif
        @if ($externalTerms)
            <p class="pt-4">Documento complementar: <a class="underline" href="{{ $externalTerms }}" rel="noopener" target="_blank">{{ $externalTerms }}</a>.</p>
        @endif
        <p class="pt-8 text-xs dark:text-neutral-400">
            <a class="underline" href="{{ route('platform.legal.privacy') }}">Política de Privacidade</a> ·
            <a class="underline" href="{{ route('platform.pricing') }}">Planos</a>
        </p>
    </article>
</x-layout-simple>
