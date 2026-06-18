# ai-runtime AWS Webhook Infrastructure

API Gateway (REST) → SQS для Telegram webhooks. **Без Lambda** — cold start нет, фильтрация в Laravel.

OpenAPI/VTL для маршрутов лежит в `openapi/webhook-api.yaml` (для чтения и ревью). В деплой уходит встроенная копия в `template.yaml` (`Fn::Sub`), чтобы подставлять ARN очереди и IAM role из того же стека.

## Что деплоится

| Ресурс | Назначение |
|--------|------------|
| `TelegramWebhookQueue` | Основная очередь (`VisibilityTimeout: 120`) |
| `TelegramWebhookDLQ` | Dead-letter queue (max 5 retries) |
| `WebhookApi` | REST API: `POST /webhooks/telegram/{channelUuid}` |
| `ApiGatewaySqsRole` | IAM role: API GW → `sqs:SendMessage` |
| `WebhookWafWebAcl` | Rate limit per IP + опционально managed rules |
| `DlqMessagesAlarm` | CloudWatch alarm при сообщениях в DLQ |
| `HomeConsumerUser` | IAM user + access key для домашнего SQS consumer |

## Требования

- [AWS CLI](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html) v2
- [AWS SAM CLI](https://docs.aws.amazon.com/serverless-application-model/latest/developerguide/install-sam-cli.html) ≥ 1.100
- Настроенные credentials (`aws configure` или env vars)
- Регион по умолчанию: `eu-central-1` (меняется в `samconfig.toml`)

## Первый деплой

```bash
make infra-deploy-guided
```

или вручную:

```bash
cd infra

# Валидация шаблона
sam validate --lint

# Сборка (копирует OpenAPI в .aws-sam/build)
sam build

# Интерактивный деплой (создаст S3 bucket для артефактов)
sam deploy --guided
```

При `--guided` укажите:

- **Stack name:** `ai-runtime-webhooks`
- **Region:** `eu-central-1` (или свой)
- **Confirm changes:** `y`
- **Allow IAM role creation:** `y`
- **Disable rollback:** `n`
- **Save arguments to samconfig.toml:** `y`

## Повторный деплой

```bash
make infra-deploy
```

или:

```bash
cd infra
sam build && sam deploy
```

Или с prod-параметрами из файла:

```bash
sam deploy --config-env prod
```

## Outputs после деплоя

```bash
aws cloudformation describe-stacks \
  --stack-name ai-runtime-webhooks \
  --query 'Stacks[0].Outputs' \
  --output table
```

Ключевые значения:

| Output | Использование |
|--------|---------------|
| `WebhookApiBaseUrl` | `PUBLIC_APP_URL` в Laravel |
| `TelegramWebhookQueueName` | `SQS_WEBHOOK_QUEUE` |
| `TelegramWebhookQueueUrl` | IAM policy / отладка |
| `HomeConsumerAccessKeyId` | `AWS_ACCESS_KEY_ID` в `.env` |
| `HomeConsumerSecretAccessKey` | `AWS_SECRET_ACCESS_KEY` (скопировать после первого деплоя) |
| `HomeConsumerSqsPrefix` | `SQS_PREFIX` в `.env` |

## Credentials для домашнего `.env`

SAM создаёт IAM user `*-home-consumer` с минимальными правами на main queue + access key.

После деплоя:

```bash
make infra-env
# или:
./scripts/print-home-env.sh
# или с другим stack name:
./scripts/print-home-env.sh ai-runtime-webhooks
```

Скопируй вывод в `.env` на домашней машине.

Вручную (если нужен только secret):

```bash
aws cloudformation describe-stacks \
  --stack-name ai-runtime-webhooks \
  --query "Stacks[0].Outputs[?OutputKey=='HomeConsumerSecretAccessKey'].OutputValue" \
  --output text
```

**Важно:**

- Secret виден в CloudFormation outputs — скопируй и не коммить.
- Этот ключ **только для SQS poll** на доме, не для `sam deploy`.
- Отключить автосоздание: `sam deploy --parameter-overrides CreateHomeConsumerUser=false`

## Публикация API на stage Prod

CloudFormation не всегда обновляет snapshot deployment при изменении OpenAPI. После изменений интеграции:

```bash
make infra-sync-api-stage
```

`make infra-deploy` вызывает это автоматически.

## Тест после деплоя

```bash
make infra-test-webhook
```

С опциями:

```bash
make infra-test-webhook INFRA_CHANNEL_UUID=your-channel-uuid
make infra-test-webhook UPDATE_ID=$(date +%s) SQS_WAIT_SECONDS=30
```

Скрипт ищет сообщение с нужным `update_id`, старые возвращает в очередь, тестовое удаляет. Показывает `visible` / `in_flight` — если `in_flight > 0`, сообщения временно скрыты после прошлого `receive-message` (до 120 сек).

Для теста используй deploy-credentials (`aws configure`). Если в shell экспортированы ключи из `.env` — сбрось: `unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY`.

Или вручную:

```bash
API_URL=$(aws cloudformation describe-stacks \
  --stack-name ai-runtime-webhooks \
  --query "Stacks[0].Outputs[?OutputKey=='WebhookApiBaseUrl'].OutputValue" \
  --output text)

curl -sS -X POST "${API_URL}/webhooks/telegram/550e8400-e29b-41d4-a716-446655440000" \
  -H 'Content-Type: application/json' \
  -H 'X-Telegram-Bot-Api-Secret-Token: test-secret' \
  -d '{"update_id":123,"message":{"text":"hello"}}'
# Ожидается: {"ok": true}
```

### Сообщение в SQS

```bash
QUEUE_URL=$(aws cloudformation describe-stacks \
  --stack-name ai-runtime-webhooks \
  --query "Stacks[0].Outputs[?OutputKey=='TelegramWebhookQueueUrl'].OutputValue" \
  --output text)

aws sqs receive-message \
  --queue-url "$QUEUE_URL" \
  --max-number-of-messages 1 \
  --wait-time-seconds 5 \
  --query 'Messages[0].Body' \
  --output text | jq .
```

Envelope:

```json
{
  "version": 1,
  "type": "agent_channel",
  "channel_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "received_at": "...",
  "request_id": "...",
  "headers": { "x-telegram-bot-api-secret-token": "..." },
  "body": { "update_id": 123, "message": { } }
}
```

## IAM user для домашнего хоста

Создаётся автоматически стеком (`HomeConsumerUser`). Ручное создание в консоли не нужно.

Права: только `ReceiveMessage`, `DeleteMessage`, `GetQueueAttributes`, `ChangeMessageVisibility` на main queue.

## Параметры

| Параметр | Default | Описание |
|----------|---------|----------|
| `QueueName` | `ai-runtime-telegram-webhooks` | Имя main queue |
| `WafRateLimit` | `1000` | Запросов / 5 мин / IP |
| `EnableManagedWafRules` | `false` | `AWSManagedRulesCommonRuleSet` (тестировать!) |
| `CreateHomeConsumerUser` | `true` | IAM user + access key для домашнего `.env` |

Override:

```bash
sam deploy --parameter-overrides WafRateLimit=2000 EnableManagedWafRules=true
```

## Удаление стека

```bash
sam delete --stack-name ai-runtime-webhooks
```

Очереди с сообщениями удаляются вместе со стеком.

## Следующие шаги (Laravel)

SAM можно задеплоить **до** Laravel consumer — сообщения копятся в SQS.

1. `PUBLIC_APP_URL` = `WebhookApiBaseUrl`
2. `SQS_WEBHOOK_QUEUE` = имя очереди
3. `TELEGRAM_WEBHOOK_INGRESS=sqs`
4. `TELEGRAM_WEBHOOK_HTTP_ENABLED=false`
5. Перерегистрировать webhooks: `php artisan telegram:set-webhook --all`

См. [docs/aws-production-security-spec.md](../docs/aws-production-security-spec.md).
