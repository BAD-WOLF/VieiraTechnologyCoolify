{{-- Política de privacidade (LGPD, art. 9º): escrita em português, a língua da lei e dos titulares. --}}
<x-layout-simple>
    <x-slot:title>Política de Privacidade | </x-slot>
    <article class="max-w-3xl px-4 py-12 mx-auto text-sm leading-relaxed dark:text-neutral-300">
        <h1 class="text-3xl font-bold dark:text-white">Política de Privacidade</h1>
        <p class="pt-1 text-xs dark:text-neutral-400">
            Versão {{ $version }}@if ($publishedAt), publicada em {{ $publishedAt->format('d/m/Y') }}@endif.
            Esta política segue a Lei Geral de Proteção de Dados Pessoais (LGPD, Lei nº 13.709/2018).
        </p>
        @if ($changes)
            <div class="p-3 mt-4 border rounded-sm border-warning">
                <strong class="dark:text-white">O que mudou nesta versão:</strong> {{ $changes }}
            </div>
        @endif

        <h2 class="pt-8 text-xl font-semibold dark:text-white">1. Quem cuida dos seus dados</h2>
        <p class="pt-2">
            O controlador dos dados pessoais tratados pela plataforma {{ $platform }} é
            <strong>{{ $controller }}</strong>{{ $document ? ', '.$document : '' }}{{ $address ? ', com endereço em '.$address : '' }}.
        </p>
        <p class="pt-2">
            @if ($officer['name'])
                O encarregado pelo tratamento de dados pessoais (art. 41) é <strong>{{ $officer['name'] }}</strong>@if ($officer['email']), pelo e-mail <a class="underline" href="mailto:{{ $officer['email'] }}">{{ $officer['email'] }}</a>@endif.
            @endif
            @if ($contact)
                Para falar sobre os seus dados e exercer os seus direitos, escreva para <a class="underline" href="mailto:{{ $contact }}">{{ $contact }}</a>@auth<span> ou use a página <a class="underline" href="{{ route('platform.privacy') }}">Privacidade e dados</a> do painel</span>@endauth.
            @endif
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">2. Que dados tratamos, para quê e com qual base legal</h2>
        <p class="pt-2">
            Tratamos só o necessário para cada finalidade (art. 6º, III). Os dados da conta e de cobrança são
            <strong>condição para prestar o serviço</strong>: sem eles não há como criar a conta nem cobrar o plano
            (art. 9º, § 3º).
        </p>
        <div class="overflow-x-auto">
            <table class="w-full mt-3 text-xs border-collapse">
                <thead>
                    <tr class="text-left dark:text-white">
                        <th class="p-2 border dark:border-coolgray-300">Tratamento</th>
                        <th class="p-2 border dark:border-coolgray-300">Dados</th>
                        <th class="p-2 border dark:border-coolgray-300">Finalidade</th>
                        <th class="p-2 border dark:border-coolgray-300">Base legal</th>
                        <th class="p-2 border dark:border-coolgray-300">Por quanto tempo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($operations as $operation)
                        <tr>
                            <td class="p-2 align-top border dark:border-coolgray-300 dark:text-white">{{ $operation['operation'] }}</td>
                            <td class="p-2 align-top border dark:border-coolgray-300">{{ $operation['data'] }}</td>
                            <td class="p-2 align-top border dark:border-coolgray-300">{{ $operation['purpose'] }}</td>
                            <td class="p-2 align-top border dark:border-coolgray-300">{{ $operation['basis'] }}</td>
                            <td class="p-2 align-top border dark:border-coolgray-300">{{ $operation['retention'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="pt-3">
            Quando usamos o legítimo interesse (registros de acesso e segurança), tratamos só os dados estritamente
            necessários e respeitamos as suas expectativas (art. 10). Promoções e novidades dependem do seu
            consentimento: você pode não consentir, e isso não muda nada no serviço; e pode retirar o consentimento a
            qualquer momento, de graça, em Privacidade e dados (arts. 8º, § 5º, e 18, VIII e IX).
        </p>
        <p class="pt-2">
            Nas aplicações e bancos de dados que você hospeda, quem decide sobre os dados é você (o controlador): a
            plataforma trata esses dados apenas para executar o serviço, seguindo as suas instruções, como operadora
            (art. 39). Cabe a você cumprir a LGPD perante os usuários das suas aplicações.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">3. Com quem compartilhamos</h2>
        <p class="pt-2">Não vendemos dados. Compartilhamos apenas com quem trata os dados em nosso nome, para as finalidades acima:</p>
        <ul class="pt-2 pl-5 list-disc">
            @foreach ($processors as $processor)
                <li><strong class="dark:text-white">{{ $processor['name'] }}</strong>: {{ $processor['purpose'] }} ({{ $processor['data'] }}).</li>
            @endforeach
        </ul>
        <p class="pt-2">
            Também podemos fornecer dados a autoridades quando a lei ou uma ordem judicial exigir. Cada um desses
            agentes responde pelo tratamento que faz, dentro do que a lei e o contrato permitem; nós respondemos
            pelas decisões sobre o tratamento.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">4. Onde os dados ficam</h2>
        <p class="pt-2">
            Os servidores ficam em: {{ $hosting }}. Quando algum fornecedor tratar dados fora do Brasil, isso é feito
            nas hipóteses do art. 33 da LGPD, com garantias contratuais de proteção equivalente.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">5. Por quanto tempo guardamos</h2>
        <ul class="pt-2 pl-5 list-disc">
            <li>Dados da conta: enquanto a conta existir. Ao excluir a conta, eles são apagados, e pedimos a quem os trata em nosso nome que os apague também (art. 18, § 6º): na Stripe, a assinatura é cancelada e o cadastro de cliente, excluído. O provedor de pagamento e o banco guardam os registros dos pagamentos pelo prazo que a lei deles exige.</li>
            <li>Registros de acesso (data, hora e IP) e o histórico de ações: {{ $auditMonths }} meses, como manda o Marco Civil da Internet (art. 15), e depois são apagados.</li>
            <li>Faturas e os dados de quem pagou: {{ $fiscalYears }} anos depois do encerramento da conta, por obrigação fiscal (art. 16, I), e depois são apagados.</li>
            <li>Provas de consentimento, solicitações de titulares e registros de contas excluídas: {{ $proofYears }} anos, para prestar contas (art. 6º, X).</li>
            <li>Cadastros que nunca confirmaram o e-mail: apagados depois de {{ $unverifiedDays }} dias.</li>
        </ul>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">6. Seus direitos</h2>
        <p class="pt-2">A qualquer momento, de graça, você pode pedir (art. 18):</p>
        <ol class="pt-2 pl-5 list-decimal">
            <li>a confirmação de que tratamos os seus dados;</li>
            <li>o acesso aos seus dados;</li>
            <li>a correção de dados incompletos, inexatos ou desatualizados;</li>
            <li>a anonimização, o bloqueio ou a eliminação de dados desnecessários, excessivos ou tratados em desconformidade com a LGPD;</li>
            <li>a portabilidade dos dados a outro fornecedor;</li>
            <li>a eliminação dos dados tratados com o seu consentimento (e a exclusão da conta), exceto o que a lei manda guardar (art. 16);</li>
            <li>a informação das entidades públicas e privadas com as quais compartilhamos dados;</li>
            <li>a informação sobre a possibilidade de não consentir e as consequências disso;</li>
            <li>a revogação do consentimento.</li>
        </ol>
        <p class="pt-2">
            Você também pode pedir a revisão de decisões tomadas só por tratamento automatizado (art. 20) e
            se opor a um tratamento feito em desconformidade com a lei (art. 18, § 2º).
            No painel, em <strong>Privacidade e dados</strong>, você vê um resumo dos seus dados na hora, baixa uma
            cópia completa (em JSON), corrige os seus dados, muda o consentimento, faz qualquer pedido e exclui a
            conta. A confirmação e o acesso em formato simplificado são imediatos; a declaração completa e as demais
            respostas saem em até 15 dias (art. 19). Quando corrigimos ou eliminamos dados, avisamos quem os recebeu
            de nós (art. 18, § 6º).
        </p>
        <p class="pt-2">
            Se não ficar satisfeito, você pode peticionar à ANPD (Agência Nacional de Proteção de Dados), em
            <a class="underline" href="https://www.gov.br/anpd" rel="noopener" target="_blank">gov.br/anpd</a>,
            e aos órgãos de defesa do consumidor (art. 18, §§ 1º e 8º).
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">7. Decisões automatizadas</h2>
        <p class="pt-2">
            Algumas decisões são automáticas: uma conta com fatura vencida há {{ $suspendDays }} dias tem novos deploys
            bloqueados, e há {{ $stopDays }} dias tem os containers parados; e uma conta que passa dos limites do plano
            não cria novos recursos. Os critérios são esses. Você pode pedir a revisão de qualquer uma dessas decisões
            por uma pessoa, em Privacidade e dados (art. 20).
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">8. Segurança e incidentes</h2>
        <p class="pt-2">
            Usamos medidas técnicas e administrativas para proteger os dados (art. 46): conexões com HTTPS, senhas
            guardadas com hash, autenticação em dois fatores, dados de pagamento criptografados, isolamento entre
            contas, discos e backups criptografados e acesso restrito pelos operadores, com registro de cada ação.
            Se um incidente de segurança puder causar risco ou dano relevante, avisamos você e a ANPD (art. 48),
            em até três dias úteis.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">9. Crianças e adolescentes</h2>
        <p class="pt-2">
            O serviço é só para maiores de 18 anos, que declaram a idade ao criar a conta. Não tratamos
            intencionalmente dados de crianças e adolescentes (art. 14); se soubermos de um caso, apagamos os dados.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">10. Cookies</h2>
        <p class="pt-2">
            Usamos só os cookies necessários para o painel funcionar: o da sessão (manter você conectado) e o de
            proteção contra falsificação de pedidos (CSRF). As suas preferências de tela (tema, largura e zoom)
            ficam só no seu navegador, no armazenamento local, e não são enviadas para nós. Não usamos cookies de
            publicidade nem de rastreamento de terceiros.
        </p>

        <h2 class="pt-8 text-xl font-semibold dark:text-white">11. Mudanças nesta política</h2>
        <p class="pt-2">
            Quando esta política ou os termos mudarem, avisamos no painel, com o que mudou em destaque, e pedimos a
            sua ciência no próximo acesso (art. 8º, § 6º). Se a mudança depender do seu consentimento, você pode
            revogá-lo.
        </p>

        @if ($policyExtra)
            <h2 class="pt-8 text-xl font-semibold dark:text-white">12. Informações adicionais</h2>
            <p class="pt-2 whitespace-pre-line">{{ $policyExtra }}</p>
        @endif
        @if ($externalPrivacy)
            <p class="pt-4">Documento complementar: <a class="underline" href="{{ $externalPrivacy }}" rel="noopener" target="_blank">{{ $externalPrivacy }}</a>.</p>
        @endif
        <p class="pt-8 text-xs dark:text-neutral-400">
            <a class="underline" href="{{ route('platform.legal.terms') }}">Termos de Uso</a> ·
            <a class="underline" href="{{ route('platform.pricing') }}">Planos</a>
        </p>
    </article>
</x-layout-simple>
