#!/usr/bin/env bash
set -euo pipefail

STACK_NAME="${1:-ai-runtime-webhooks}"
REGION="${AWS_DEFAULT_REGION:-eu-central-1}"

output() {
  aws cloudformation describe-stacks \
    --stack-name "$STACK_NAME" \
    --region "$REGION" \
    --query "Stacks[0].Outputs[?OutputKey=='${1}'].OutputValue | [0]" \
    --output text
}

PUBLIC_APP_URL="$(output WebhookApiBaseUrl)"
SQS_WEBHOOK_QUEUE="$(output TelegramWebhookQueueName)"
SQS_PREFIX="$(output HomeConsumerSqsPrefix)"
AWS_ACCESS_KEY_ID="$(output HomeConsumerAccessKeyId)"
AWS_SECRET_ACCESS_KEY="$(output HomeConsumerSecretAccessKey)"
AWS_DEFAULT_REGION="$REGION"

cat <<EOF
# Paste into .env (home host SQS consumer — created by SAM stack)
PUBLIC_APP_URL=${PUBLIC_APP_URL}
SQS_WEBHOOK_QUEUE=${SQS_WEBHOOK_QUEUE}
SQS_PREFIX=${SQS_PREFIX}
AWS_ACCESS_KEY_ID=${AWS_ACCESS_KEY_ID}
AWS_SECRET_ACCESS_KEY=${AWS_SECRET_ACCESS_KEY}
AWS_DEFAULT_REGION=${AWS_DEFAULT_REGION}
TELEGRAM_WEBHOOK_INGRESS=sqs
TELEGRAM_WEBHOOK_HTTP_ENABLED=false
EOF
