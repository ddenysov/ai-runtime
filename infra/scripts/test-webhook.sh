#!/usr/bin/env bash
set -euo pipefail

STACK_NAME="${1:-ai-runtime-webhooks}"
CHANNEL_UUID="${2:-550e8400-e29b-41d4-a716-446655440000}"
REGION="${AWS_DEFAULT_REGION:-eu-central-1}"
UPDATE_ID="${UPDATE_ID:-$(date +%s)}"

output() {
  aws cloudformation describe-stacks \
    --stack-name "$STACK_NAME" \
    --region "$REGION" \
    --query "Stacks[0].Outputs[?OutputKey=='${1}'].OutputValue | [0]" \
    --output text
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "error: '$1' not found in PATH" >&2
    exit 1
  }
}

require_cmd aws
require_cmd curl

API_URL="$(output WebhookApiBaseUrl)"
QUEUE_URL="$(output TelegramWebhookQueueUrl)"

if [ -z "$API_URL" ] || [ "$API_URL" = "None" ]; then
  echo "error: stack '$STACK_NAME' not found or WebhookApiBaseUrl missing (region: $REGION)" >&2
  exit 1
fi

if [ -z "$QUEUE_URL" ] || [ "$QUEUE_URL" = "None" ]; then
  echo "error: TelegramWebhookQueueUrl missing in stack '$STACK_NAME'" >&2
  exit 1
fi

WEBHOOK_URL="${API_URL}/webhooks/telegram/${CHANNEL_UUID}"
PAYLOAD=$(cat <<EOF
{
  "update_id": ${UPDATE_ID},
  "message": {
    "message_id": 1,
    "date": ${UPDATE_ID},
    "chat": { "id": 12345, "type": "private" },
    "text": "hello test"
  }
}
EOF
)

echo "→ POST ${WEBHOOK_URL}"
HTTP_BODY="$(curl -sS -w '\n%{http_code}' -X POST "$WEBHOOK_URL" \
  -H 'Content-Type: application/json' \
  -H 'X-Telegram-Bot-Api-Secret-Token: test-secret' \
  -d "$PAYLOAD")"

HTTP_CODE="${HTTP_BODY##*$'\n'}"
RESPONSE="${HTTP_BODY%$'\n'*}"

echo "← HTTP ${HTTP_CODE}: ${RESPONSE}"

if [ "$HTTP_CODE" != "200" ] || [ "$RESPONSE" != '{"ok": true}' ]; then
  echo "error: expected HTTP 200 and {\"ok\": true} (if 5xx after redeploy, SQS integration failed — check API GW logs)" >&2
  exit 1
fi

echo "→ receive-message from SQS (wait up to 5s)..."
MESSAGE_BODY="$(aws sqs receive-message \
  --queue-url "$QUEUE_URL" \
  --region "$REGION" \
  --max-number-of-messages 1 \
  --wait-time-seconds 5 \
  --query 'Messages[0].Body' \
  --output text)"

if [ -z "$MESSAGE_BODY" ] || [ "$MESSAGE_BODY" = "None" ]; then
  echo "error: no message in queue (wrong region or delivery failed)" >&2
  exit 1
fi

echo "← SQS message:"
if command -v jq >/dev/null 2>&1; then
  echo "$MESSAGE_BODY" | jq .
else
  echo "$MESSAGE_BODY"
fi

if command -v jq >/dev/null 2>&1; then
  RECEIVED_UPDATE_ID="$(echo "$MESSAGE_BODY" | jq -r '.body.update_id // empty')"
  if [ "$RECEIVED_UPDATE_ID" != "$UPDATE_ID" ]; then
    echo "warn: update_id in queue ($RECEIVED_UPDATE_ID) != sent ($UPDATE_ID); older message?" >&2
  fi
fi

echo "ok: webhook reached SQS"
