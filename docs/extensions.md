# Extensões e integrações

Como integrar sistemas externos ao Hostrema e estender o fork sem editar os arquivos do Coolify
(wiki 9, "Plugins e Hooks" e "API Interna").

## API

- Coolify: `openapi.yaml` na raiz do repositório (aplicações, bancos, serviços, deploys, servidores...).
- Plataforma: `GET /api/v1/platform/openapi.json` (planos, uso, faturas, logs, webhooks, rollback).

Autentique com um token do time (`Authorization: Bearer <token>`), criado em **Keys & Tokens → API tokens**.

## Eventos

| Evento | Quando |
| --- | --- |
| `deployment.queued`, `deployment.started`, `deployment.finished`, `deployment.failed`, `deployment.cancelled` | Ciclo de um deploy |
| `resource.created`, `resource.deleted` | Aplicação, serviço ou banco criado ou excluído |
| `resource.status_changed` | Containers de um recurso mudaram de estado (`status`, `previous_status`, `component` nos serviços) |
| `backup.succeeded`, `backup.failed` | Backup de banco |
| `tenant.plan_changed`, `tenant.status_changed` | Plano ou status da conta (ativa, limitada, suspensa...) |
| `invoice.created`, `invoice.paid`, `invoice.status_changed` | Faturas |
| `webhook.test` | Botão "Enviar evento de teste" ou `POST /api/v1/platform/webhooks/{uuid}/test` |

Formato (igual nos três canais):

```json
{
  "id": "evt_01jcz3kq8m7d0f9x2v6n4b5t1a",
  "type": "deployment.finished",
  "created_at": "2026-11-15T17:00:00.000Z",
  "team_id": 42,
  "data": {
    "deployment_uuid": "x8k2...",
    "status": "finished",
    "application": { "uuid": "j8s...", "name": "loja", "type": "application" },
    "commit": "a1b2c3d",
    "pull_request_id": 0,
    "rollback": false
  }
}
```

O mesmo evento pode chegar mais de uma vez: use o `id` para descartar repetidos.

## Webhooks

Cadastre em **Integrações** (ou `POST /api/v1/platform/webhooks`). O segredo (`whsec_...`) aparece uma vez.
Cada entrega traz `X-Hostrema-Event`, `X-Hostrema-Delivery` e `X-Hostrema-Signature: t=<unix>,v1=<hex>`,
onde `v1` é o HMAC-SHA256 de `"<t>.<corpo cru>"` com o segredo. Responda 2xx em até 10 s. Erros de rede, 408,
429 e 5xx são repetidos (8 tentativas em cerca de 9 horas); outras respostas não. Depois de 50 falhas seguidas o
webhook é desativado (o botão Ativar volta a ligá-lo).

PHP:

```php
[$t, $v1] = sscanf($_SERVER['HTTP_X_HOSTREMA_SIGNATURE'], 't=%d,v1=%s');
$body = file_get_contents('php://input');
$valid = abs(time() - $t) < 300 && hash_equals(hash_hmac('sha256', $t.'.'.$body, getenv('HOSTREMA_WEBHOOK_SECRET')), $v1);
```

Node.js:

```js
const crypto = require('crypto');
function valid(header, rawBody, secret) {
  const { t, v1 } = Object.fromEntries(header.split(',').map((part) => part.split('=')));
  const expected = crypto.createHmac('sha256', secret).update(`${t}.${rawBody}`).digest('hex');
  return typeof v1 === 'string' && v1.length === expected.length
    && Math.abs(Date.now() / 1000 - Number(t)) < 300
    && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(v1));
}
```

## Stream no Redis

Serviços internos (na rede do painel) leem o stream `hostrema:events` do Redis do control plane, com grupo de
consumo para não perder eventos:

```bash
redis-cli XGROUP CREATE hostrema:events crm $ MKSTREAM
redis-cli XREADGROUP GROUP crm worker-1 COUNT 10 BLOCK 5000 STREAMS hostrema:events '>'
redis-cli XACK hostrema:events crm <id>
```

Os campos de cada entrada são `id`, `type`, `team_id`, `created_at` e `data` (JSON). O stream guarda os últimos
100 mil eventos (`PLATFORM_EVENTS_STREAM_MAX_LENGTH`).

## Dentro do fork (Laravel)

Registre listeners num service provider próprio (arquivo novo, sem conflito com o upstream):

```php
use App\Events\PlatformEventOccurred;
use Illuminate\Support\Facades\Event;

Event::listen(PlatformEventOccurred::class, function (PlatformEventOccurred $event) {
    if ($event->type === 'deployment.failed') {
        // abrir um chamado, avisar no Slack...
    }
});
```

Listeners de eventos de modelo nunca devem retornar `false`: o Laravel interrompe o evento e os listeners
seguintes, inclusive os do próprio Coolify, não rodam.
