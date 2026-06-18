#!/usr/bin/env bash
set -euo pipefail

STACK_NAME="${1:-ai-runtime-webhooks}"
CHANNEL_UUID="${2:-550e8400-e29b-41d4-a716-446655440000}"
REGION="${AWS_DEFAULT_REGION:-eu-central-1}"
UPDATE_ID="${UPDATE_ID:-$(date +%s)}"
SQS_WAIT_SECONDS="${SQS_WAIT_SECONDS:-25}"
DELETE_TEST_MESSAGE="${DELETE_TEST_MESSAGE:-true}"

output() {
  aws cloudformation describe-stacks \
    --stack-name "$STACK_NAME" \
    --region "$REGION" \
    --query "Stacks[0].Outputs[?OutputKey=='${1}'].OutputValue | [0]" \
    --output text
}

queue_stats() {
  aws sqs get-queue-attributes \
    --queue-url "$QUEUE_URL" \
    --region "$REGION" \
    --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible ApproximateNumberOfMessagesDelayed \
    --output json
}

print_queue_stats() {
  if command -v jq >/dev/null 2>&1; then
    local stats
    stats="$(queue_stats)"
    echo "queue stats: visible=$(echo "$stats" | jq -r '.Attributes.ApproximateNumberOfMessages') in_flight=$(echo "$stats" | jq -r '.Attributes.ApproximateNumberOfMessagesNotVisible') delayed=$(echo "$stats" | jq -r '.Attributes.ApproximateNumberOfMessagesDelayed')"
  else
    echo "queue stats: (install jq for details)"
  fi
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "error: '$1' not found in PATH" >&2
    exit 1
  }
}

require_cmd aws
require_cmd curl

json_get() {
  local json="$1"
  local filter="$2"
  if command -v jq >/dev/null 2>&1; then
    printf '%s' "$json" | jq -r "$filter"
  else
    printf '%s' "$json" | python3 -c "import json,sys; d=json.load(sys.stdin); print($filter)" 2>/dev/null
  fi
}

update_id_from_envelope() {
  local body="$1"
  printf '%s' "$body" | python3 -c 'import json,sys
try:
    d=json.load(sys.stdin)
    print(d.get("body", {}).get("update_id", ""))
except Exception:
    print("")' 2>/dev/null || true
}

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

echo "using update_id=${UPDATE_ID} region=${REGION}"
print_queue_stats

echo "→ POST ${WEBHOOK_URL}"
HTTP_BODY="$(curl -sS -w '\n%{http_code}' -X POST "$WEBHOOK_URL" \
  -H 'Content-Type: application/json' \
  -H 'X-Telegram-Bot-Api-Secret-Token: test-secret' \
  -d "$PAYLOAD")"

HTTP_CODE="${HTTP_BODY##*$'\n'}"
RESPONSE="${HTTP_BODY%$'\n'*}"

echo "← HTTP ${HTTP_CODE}: ${RESPONSE}"

if [ "$HTTP_CODE" != "200" ] || [ "$RESPONSE" != '{"ok": true}' ]; then
  echo "error: expected HTTP 200 and {\"ok\": true}" >&2
  exit 1
fi

echo "→ polling SQS for update_id=${UPDATE_ID} (up to ${SQS_WAIT_SECONDS}s)..."

deadline=$(( $(date +%s) + SQS_WAIT_SECONDS ))
MESSAGE_BODY=""
RECEIPT_HANDLE=""

while [ "$(date +%s)" -lt "$deadline" ]; do
  remaining=$(( deadline - $(date +%s) ))
  wait_seconds=5
  if [ "$remaining" -lt "$wait_seconds" ]; then
    wait_seconds="$remaining"
  fi
  if [ "$wait_seconds" -le 0 ]; then
    break
  fi

  RECEIVE_JSON="$(aws sqs receive-message \
    --queue-url "$QUEUE_URL" \
    --region "$REGION" \
    --max-number-of-messages 1 \
    --wait-time-seconds "$wait_seconds" \
    --visibility-timeout 30 \
    --output json)"

  RECEIVE_DIR="$(mktemp -d)"
  printf '%s' "$RECEIVE_JSON" | python3 -c 'import json,sys,pathlib
d=json.load(sys.stdin)
m=(d.get("Messages") or [None])[0]
base=pathlib.Path(sys.argv[1])
if not m:
    (base / "body").write_text("")
    (base / "receipt").write_text("")
else:
    (base / "body").write_text(m.get("Body", ""))
    (base / "receipt").write_text(m.get("ReceiptHandle", ""))' "$RECEIVE_DIR"
  MESSAGE_BODY="$(cat "$RECEIVE_DIR/body")"
  RECEIPT_HANDLE="$(cat "$RECEIVE_DIR/receipt")"
  rm -rf "$RECEIVE_DIR"

  if [ -z "$MESSAGE_BODY" ] || [ "$MESSAGE_BODY" = "None" ]; then
    continue
  fi

  RECEIVED_UPDATE_ID="$(update_id_from_envelope "$MESSAGE_BODY")"

  if [ -z "$RECEIVED_UPDATE_ID" ]; then
    echo "… removing invalid/empty SQS message"
    if [ -n "$RECEIPT_HANDLE" ]; then
      aws sqs delete-message \
        --queue-url "$QUEUE_URL" \
        --region "$REGION" \
        --receipt-handle "$RECEIPT_HANDLE" >/dev/null
    fi
    MESSAGE_BODY=""
    RECEIPT_HANDLE=""
    continue
  fi

  if [ "$RECEIVED_UPDATE_ID" = "$UPDATE_ID" ]; then
    break
  fi

  echo "… removing older test message update_id=${RECEIVED_UPDATE_ID}"
  if [ -n "$RECEIPT_HANDLE" ]; then
    aws sqs delete-message \
      --queue-url "$QUEUE_URL" \
      --region "$REGION" \
      --receipt-handle "$RECEIPT_HANDLE" >/dev/null
  fi
  MESSAGE_BODY=""
  RECEIPT_HANDLE=""
done

if [ -z "$MESSAGE_BODY" ] || [ "$MESSAGE_BODY" = "None" ]; then
  echo "error: message with update_id=${UPDATE_ID} not found in queue" >&2
  print_queue_stats
  echo "hint: run 'make infra-sync-api-stage' if API returns 200 but queue stays empty (stale API Gateway deployment)" >&2
  echo "hint: ensure AWS CLI uses deploy credentials; unset AWS_ACCESS_KEY_ID from .env if needed" >&2
  exit 1
fi

echo "← SQS message:"
if command -v jq >/dev/null 2>&1; then
  printf '%s' "$MESSAGE_BODY" | jq . 2>/dev/null || printf '%s\n' "$MESSAGE_BODY"
else
  printf '%s\n' "$MESSAGE_BODY"
fi

if [ "$DELETE_TEST_MESSAGE" = "true" ] && [ -n "$RECEIPT_HANDLE" ]; then
  aws sqs delete-message \
    --queue-url "$QUEUE_URL" \
    --region "$REGION" \
    --receipt-handle "$RECEIPT_HANDLE" >/dev/null
  echo "… deleted test message from queue"
fi

echo "ok: webhook reached SQS"
