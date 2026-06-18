# AWS Production Security — Implementation Spec

> **Назначение:** самодостаточная спецификация для реализации production-инфраструктуры ai-runtime.  
> **Статус:** не реализовано (`infra/` отсутствует).  
> **Последнее обновление:** 2026-06-18

---

## 1. Цель

Защитить home-hosted ai-runtime от сканирования, сохранив:

1. **Telegram webhooks** — публичный HTTPS в AWS.
2. **Admin UI** — только LAN (домашняя сеть).
3. **A2A** — только localhost, без HTTP наружу.

### Hard requirements

| # | Требование |
|---|------------|
| 1 | **Роутер закрыт** — никакого port forward для app |
| 2 | **Приложение дома** — `docker-compose` (app, postgres, workers) |
| 3 | **AWS без EC2** — только API Gateway + SQS (+ WAF) |
| 4 | **Без Lambda** — API Gateway → SQS напрямую (нет cold start) |
| 5 | **Фильтрация в Laravel** — secret, dedup в приложении |
| 6 | **Admin UI только LAN** — без Tailscale, VPN, Cloudflare Tunnel |

### Убрать (текущий проблемный setup)

```
Сканеры → EC2 → reverse SSH tunnel → дом
```

После миграции: **EC2 и reverse SSH выключить**.

### Целевой setup

```
Telegram → API Gateway (REST) → SQS (+ DLQ)
                                    ↑ long poll, outbound HTTPS
                               Дом (docker compose)

Admin UI → http://192.168.x.x  (только LAN)
```

---

## 2. Текущее состояние приложения

### 2.1 Стек

- Laravel + PostgreSQL + Vue SPA
- Production host: **домашняя машина**, `docker-compose.yml`
- Очереди: `QUEUE_CONNECTION=database`, `queue-worker` в compose
- AWS: EC2 с reverse SSH (убрать); SAM не настроен
- Dev webhooks: `make ngrok` (только local/dev)

### 2.2 HTTP-эндпоинты

Файл: `routes/api.php`

| Path | Prod |
|------|------|
| `POST /api/integrations/telegram/webhooks/{uuid}` | **HTTP off**, только SQS |
| `GET/POST /api/a2a/*` | **Скрыть** (`A2A_EXPOSE_HTTP=false`) |
| Остальное `/api/*` | Session auth, доступ **только LAN** |

### 2.3 Webhook URL (после миграции)

- `PUBLIC_APP_URL` = base URL API Gateway (stage `Prod`)
- Agent channel: `{PUBLIC_APP_URL}/webhooks/telegram/{channel.uuid}`

Регистратор: `TelegramWebhookRegistrar` — config-driven path.

### 2.4 Обработка Telegram

- `TelegramAgentWebhookController` → `TelegramIncomingMessageHandler`
- `update_id` в metadata; **dedup в SQS consumer** (ещё не реализован)

### 2.5 Prod docker

- Postgres **без** exposed ports
- Без `pgadmin` / `pgweb` в prod overlay
- nginx на `0.0.0.0:80` в LAN — ок (WAN не проброшен)

---

## 3. Архитектура

```
                         Internet
                             │
                      [Telegram]
                             │
                             ▼
              ┌──────────────────────────┐
              │  API Gateway (REST API)  │
              │  + WAF                   │
              │  AWS integration → SQS   │  ← без Lambda
              │  response: {"ok":true}   │
              └────────────┬─────────────┘
                           │
                           ▼
              ┌──────────────────────────┐
              │  SQS telegram-webhooks   │──► DLQ
              └────────────┬─────────────┘
                           │ ReceiveMessage (outbound)
                           ▼
    ┌──────────────────────────────────────────────────┐
    │  HOME (NAT, router closed)                        │
    │  docker compose:                                  │
    │    app, nginx, postgres                           │
    │    queue-worker, scheduler                        │
    │    telegram-webhook-consumer  ← artisan SQS poll  │
    │                                                   │
    │  Admin: http://192.168.x.x  (LAN only)            │
    └──────────────────────────────────────────────────┘
```

### Трафик

| Поток | Направление | Inbound на роутер |
|-------|-------------|-------------------|
| Telegram webhook | TG → API GW → SQS → дом poll | **Нет** |
| App → Telegram / LLM | Outbound | Нет |
| Admin UI | LAN `192.168.x.x` | Только локальная сеть |
| WAN → дом | — | **Закрыто** |

### Публично в интернете

Только **API Gateway** (один POST route на agent channel). Домашний IP не светится.

---

## 4. AWS (SAM) — API Gateway → SQS, без Lambda

### 4.1 Почему REST API, не HTTP API

**REST API** поддерживает нативную AWS service integration с SQS (`SendMessage`).  
HTTP API — нет прямой интеграции с SQS без Lambda.

### 4.2 Структура `infra/`

```
infra/
  template.yaml           # CloudFormation + SAM (Api, SQS, IAM, WAF)
  samconfig.toml
  openapi/
    webhook-api.yaml      # OpenAPI 3 + x-amazon-apigateway-integration
  parameters/
    prod.json
  README.md
```

> Lambda **не создаём**. SAM используем для удобного deploy; ресурсы — Api, SQS, Roles, WAF.

### 4.3 Resources

| Resource | Type | Назначение |
|----------|------|------------|
| `TelegramWebhookDLQ` | `AWS::SQS::Queue` | Failed processing |
| `TelegramWebhookQueue` | `AWS::SQS::Queue` | Main queue, `VisibilityTimeout: 120`, redrive max 5 |
| `WebhookRestApi` | `AWS::ApiGateway::RestApi` | REST API из OpenAPI |
| `WebhookApiDeployment` | `AWS::ApiGateway::Deployment` | Stage `Prod` |
| `WebhookApiStage` | `AWS::ApiGateway::Stage` | + WAF association |
| `ApiGatewaySqsRole` | `AWS::IAM::Role` | `sqs:SendMessage` на main queue |
| `WebhookWafWebAcl` | `AWS::WAFv2::WebACL` | Rate limit + managed rules |

### 4.4 Routes

Base URL (output): `https://{api-id}.execute-api.{region}.amazonaws.com/Prod`

| Method | Path | SQS envelope `type` |
|--------|------|----------------------|
| POST | `/webhooks/telegram/{channelUuid}` | `agent_channel` |

### 4.5 API Gateway → SQS integration

**Тип:** `AWS` service integration, action `SendMessage`.

**IAM:** API Gateway execution role (`ApiGatewaySqsRole`) с policy:

```json
{
  "Effect": "Allow",
  "Action": "sqs:SendMessage",
  "Resource": "<TelegramWebhookQueue ARN>"
}
```

**Integration URI:**

```
arn:aws:apigateway:{region}:sqs:path/{account-id}/{queue-name}
```

**Request mapping** (`application/json` → `application/x-www-form-urlencoded` для SQS API):

API Gateway вызывает SQS `SendMessage` с `MessageBody` = JSON envelope.

Пример VTL для `/webhooks/telegram/{channelUuid}`:

```vtl
#set($channelUuid = $input.params('channelUuid'))
#set($secret = $input.params().header.get('X-Telegram-Bot-Api-Secret-Token'))
#if(!$secret)
  #set($secret = '')
#end
#set($payload = {
  "version": 1,
  "type": "agent_channel",
  "channel_uuid": "$channelUuid",
  "received_at": "$context.requestTime",
  "request_id": "$context.requestId",
  "headers": {
    "x-telegram-bot-api-secret-token": "$secret"
  },
  "body": $input.json('$')
})
Action=SendMessage&MessageBody=$util.urlEncode($util.toJson($payload))
```

**Response mapping** (integration response → клиент):

Telegram ожидает `200` + `{"ok": true}`. SQS возвращает свой XML/JSON — перехватываем:

```vtl
#set($inputRoot = $input.path('$'))
{"ok": true}
```

Content-Type: `application/json`, status `200`.

**Ошибки integration:** настроить `200` + `{"ok": true}` и для 4xx/5xx mapping где возможно — Telegram ретраит при non-2xx. При падении SendMessage — логи CloudWatch API GW.

### 4.6 SQS message contract (что читает Laravel)

```json
{
  "version": 1,
  "type": "agent_channel",
  "channel_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "received_at": "18/Jun/2026:12:00:00 +0000",
  "request_id": "api-gw-request-id",
  "headers": {
    "x-telegram-bot-api-secret-token": "optional-secret"
  },
  "body": {
    "update_id": 123456789,
    "message": { }
  }
}
```

**Валидация в Laravel (не в API GW):**

- `agent_channel`: secret header, channel exists, enabled, dedup `update_id`
- Мусор от сканеров: ack в consumer не нужен (уже ack на API GW); просто drop + log

### 4.7 WAF

На stage `Prod`:

- Rate-based rule (например 1000 req/5min per IP)
- `AWSManagedRulesCommonRuleSet` (опционально, может резать лишнее — тестировать)

### 4.8 SAM Outputs

```yaml
Outputs:
  WebhookApiBaseUrl:
    Value: !Sub 'https://${WebhookRestApi}.execute-api.${AWS::Region}.amazonaws.com/Prod'
  TelegramWebhookQueueUrl:
    Value: !Ref TelegramWebhookQueue
  TelegramWebhookQueueArn:
    Value: !GetAtt TelegramWebhookQueue.Arn
  TelegramWebhookDLQUrl:
    Value: !Ref TelegramWebhookDLQ
```

### 4.9 Deploy

```bash
cd infra
sam build    # если openapi вложен в template
sam deploy --guided
```

### 4.10 Стоимость / плюсы без Lambda

| | API GW → SQS | API GW → Lambda → SQS |
|--|--------------|----------------------|
| Cold start | **Нет** | Да (100ms–сек) |
| Сложность | VTL mapping | Код + deploy |
| Фильтрация | Только в Laravel | Lambda + Laravel |
| Стоимость | Ниже | Lambda invocations |

---

## 5. Домашний хост

### 5.1 docker compose (prod)

Сервисы:

| Service | Назначение |
|---------|------------|
| `app` | PHP-FPM |
| `nginx` | `0.0.0.0:80` в LAN |
| `postgres` | без host ports |
| `queue-worker` | internal jobs (`database` driver) |
| `scheduler` | `schedule:work` |
| `telegram-webhook-consumer` | `php artisan telegram:consume-webhooks` |

`docker-compose.prod.yml` — overlay: убрать pgadmin/pgweb, убрать postgres ports.

### 5.2 Admin UI (LAN only)

- `APP_URL=http://192.168.x.x` (или hostname в `/etc/hosts` LAN)
- Доступ с телефона/ноутбука **только в Wi‑Fi дома**
- `GATE_ENABLED` — опционально; на закрытом LAN можно `false`
- Удалённого доступа нет — осознанный trade-off

### 5.3 AWS credentials на доме

IAM user `*-home-consumer` и access key создаются **SAM-стеком** (`CreateHomeConsumerUser=true`).

Минимальные права (inline policy на user):

```json
{
  "Effect": "Allow",
  "Action": [
    "sqs:ReceiveMessage",
    "sqs:DeleteMessage",
    "sqs:GetQueueAttributes",
    "sqs:ChangeMessageVisibility"
  ],
  "Resource": "<TelegramWebhookQueue ARN>"
}
```

После деплоя: `infra/scripts/print-home-env.sh` → вставить в `.env`.

### 5.4 Роутер

- **Нет** port forward 80/443/22 на app
- WAN scan домашнего IP → closed

### 5.5 Бэкапы

- `pg_dump` по cron локально или в S3 (outbound `s3:PutObject`)

---

## 6. Изменения в Laravel

### 6.1 Env (`.env.example` + prod)

```dotenv
# Telegram webhooks → API Gateway (HTTPS)
PUBLIC_APP_URL=https://xxx.execute-api.region.amazonaws.com/Prod

# App URL for LAN
APP_URL=http://192.168.1.100

# Webhook ingress
TELEGRAM_WEBHOOK_INGRESS=sqs          # direct | sqs
TELEGRAM_WEBHOOK_HTTP_ENABLED=false   # prod: false
TELEGRAM_WEBHOOK_AGENT_PATH=/webhooks/telegram/{uuid}

# SQS (home polls outbound)
SQS_WEBHOOK_QUEUE=ai-runtime-telegram-webhooks
SQS_PREFIX=https://sqs.region.amazonaws.com/account-id
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=eu-central-1

A2A_EXPOSE_HTTP=false
QUEUE_CONNECTION=database
```

### 6.2 `config/telegram.php`

```php
return [
    'webhook' => [
        'agent_channel_path' => env('TELEGRAM_WEBHOOK_AGENT_PATH', '/webhooks/telegram/{uuid}'),
        'http_enabled' => filter_var(env('TELEGRAM_WEBHOOK_HTTP_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],
    'ingress' => env('TELEGRAM_WEBHOOK_INGRESS', 'direct'),
    'sqs_queue' => env('SQS_WEBHOOK_QUEUE'),
];
```

### 6.3 Registrars

`TelegramWebhookRegistrar::webhookUrlFor()`:

```php
$path = str_replace('{uuid}', $channel->uuid, config('telegram.webhook.agent_channel_path'));
return self::resolvePublicHttpsBase().$path;
```

### 6.4 SQS consumer

```
app/Telegram/Webhook/
  TelegramWebhookMessage.php       # DTO from SQS JSON
  TelegramWebhookIngress.php       # dispatch by type
  TelegramUpdateDeduplicator.php   # Cache::add update_id
app/Console/Commands/
  ConsumeTelegramWebhookQueue.php  # long poll loop
```

**`TelegramWebhookIngress`:**

1. `agent_channel` → find `AgentChannel` by UUID → validate secret (из `TelegramAgentWebhookController`) → dedup → `TelegramIncomingMessageHandler::handle()`
2. Unknown / invalid → log, delete message (не rethrow бесконечно; после N попыток → DLQ via SQS redrive)

**Не использовать** `queue:work sqs` — body не Laravel serialized job.

### 6.5 Routes gating

`routes/api.php`:

```php
if (config('telegram.webhook.http_enabled')) {
    Route::post('/integrations/telegram/webhooks/{agentChannel}', ...);
}

if (config('runtime-agents.expose_http')) {
    Route::get('/a2a/{agent}/.well-known/agent-card.json', ...);
    Route::middleware('auth.a2a')->group(...);
}
```

`config/runtime-agents.php`: `'expose_http' => env('A2A_EXPOSE_HTTP', false)`.

### 6.6 Dedup

```php
public function isDuplicate(string $scope, int $updateId): bool
{
    $key = "telegram:update:{$scope}:{$updateId}";
    return ! Cache::add($key, 1, now()->addDay());
}
```

`$scope` = `channel_uuid`.

---

## 7. Миграция

### Фаза 0

- [ ] Убедиться: нет port forward на роутере
- [ ] AWS region выбран
- [ ] Зафиксировать LAN IP машины для `APP_URL`

### Фаза 1 — SAM (API GW → SQS)

- [ ] `infra/template.yaml` + OpenAPI integrations
- [ ] SQS + DLQ
- [ ] API GW execution role → SQS
- [ ] WAF на stage
- [ ] `sam deploy`
- [ ] Тест: `curl -X POST {ApiUrl}/webhooks/telegram/{uuid} ...` → `{"ok":true}` + сообщение в SQS
- [ ] IAM user для дома (SQS read)

### Фаза 2 — Laravel

- [ ] `config/telegram.php`, registrars, route gating
- [ ] SQS consumer + dedup + tests
- [ ] `docker-compose.prod.yml` + `telegram-webhook-consumer`
- [ ] Prod `.env` на домашней машине

### Фаза 3 — Переключение

- [ ] `PUBLIC_APP_URL` = API GW URL
- [ ] `TELEGRAM_WEBHOOK_HTTP_ENABLED=false`
- [ ] Перерегистрировать webhooks (`telegram:set-webhook --all`)
- [ ] E2E: Telegram message → SQS → ответ бота

### Фаза 4 — Cleanup

- [ ] Остановить reverse SSH
- [ ] Удалить EC2
- [ ] CloudWatch alarm: DLQ `ApproximateNumberOfMessagesVisible` > 0
- [ ] WAN port scan — closed

---

## 8. Тестирование

| Test | Файл |
|------|------|
| Webhook URL с API GW paths | `tests/Unit/Channels/TelegramWebhookRegistrarTest.php` |
| SQS envelope → handlers | `tests/Feature/TelegramWebhookIngressTest.php` |
| Dedup | `tests/Unit/Telegram/TelegramUpdateDeduplicatorTest.php` |
| HTTP routes off | `tests/Feature/TelegramWebhookRoutesTest.php` |
| A2A hidden | `tests/Feature/A2AExposureTest.php` |

**Manual:**

1. `aws sqs receive-message` после curl в API GW
2. Telegram round-trip
3. UI только из LAN
4. Сканер/WAN на домашний IP — closed
5. EC2 старый IP — не отвечает

---

## 9. Локальная разработка

| Env | Поведение |
|-----|-----------|
| `TELEGRAM_WEBHOOK_INGRESS=direct` | HTTP webhooks на Laravel |
| `TELEGRAM_WEBHOOK_HTTP_ENABLED=true` | Routes on |
| `PUBLIC_APP_URL` | `make ngrok` — только dev |

`make ngrok`, `make tg` — не удалять.

---

## 10. Не использовать

| Подход | Причина |
|--------|---------|
| Lambda в webhook path | Cold start; не нужен |
| EC2 / reverse SSH | Сканирование; inbound |
| Port forward | Inbound на роутер |
| Tailscale / VPN для UI | Решение: LAN only |
| RDS | Postgres в docker дома |
| `queue:work sqs` для webhooks | Custom JSON, не Laravel jobs |
| IP whitelist на API GW для webhooks | Заблокирует Telegram |

---

## 11. Definition of Done

- [ ] API Gateway → SQS напрямую, без Lambda
- [ ] WAF + DLQ + alarm на DLQ
- [ ] Дом poll SQS (outbound only)
- [ ] Роутер без port forward
- [ ] EC2 / reverse SSH выключены
- [ ] `PUBLIC_APP_URL` = API GW; webhooks перерегистрированы
- [ ] HTTP webhook routes off на prod
- [ ] Фильтрация (secret, dedup) в Laravel
- [ ] A2A HTTP скрыт
- [ ] Admin UI работает по LAN
- [ ] `infra/README.md` с deploy и curl-примерами
- [ ] Тесты §8 проходят

---

## 12. Порядок PR

1. `docs/aws-production-security-spec.md` (этот файл)
2. `config/telegram.php` + registrars + `routes/api.php` gating + `runtime-agents.expose_http`
3. `app/Telegram/Webhook/*` + `ConsumeTelegramWebhookQueue` + tests
4. `infra/` — SAM, OpenAPI, SQS, API GW integration, WAF, README
5. `.env.example`
6. `docker-compose.prod.yml` + `telegram-webhook-consumer`

SAM можно задеплоить до Laravel — сообщения копятся в SQS.

---

## 13. Файлы для чтения при старте

```
routes/api.php
app/Channels/Services/TelegramWebhookRegistrar.php
app/Channels/Http/Controllers/TelegramAgentWebhookController.php
app/Channels/Services/TelegramIncomingMessageHandler.php
config/app.php
config/runtime-agents.php
docker-compose.yml
Makefile
```
