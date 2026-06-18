#!/usr/bin/env bash
set -euo pipefail

STACK_NAME="${1:-ai-runtime-webhooks}"
STAGE_NAME="${2:-Prod}"
REGION="${AWS_DEFAULT_REGION:-eu-central-1}"

api_id() {
  aws cloudformation describe-stacks \
    --stack-name "$STACK_NAME" \
    --region "$REGION" \
    --query "Stacks[0].Outputs[?OutputKey=='WebhookApiId'].OutputValue | [0]" \
    --output text
}

API_ID="$(api_id)"
if [ -z "$API_ID" ] || [ "$API_ID" = "None" ]; then
  echo "error: WebhookApiId not found for stack $STACK_NAME" >&2
  exit 1
fi

DESCRIPTION="publish-$(date -u +%Y%m%dT%H%M%SZ)"
echo "→ create-deployment rest-api-id=${API_ID} (${DESCRIPTION})"
DEPLOYMENT_ID="$(aws apigateway create-deployment \
  --rest-api-id "$API_ID" \
  --region "$REGION" \
  --description "$DESCRIPTION" \
  --query 'id' \
  --output text)"

echo "→ update-stage ${STAGE_NAME} → deployment ${DEPLOYMENT_ID}"
aws apigateway update-stage \
  --rest-api-id "$API_ID" \
  --region "$REGION" \
  --stage-name "$STAGE_NAME" \
  --patch-operations "op=replace,path=/deploymentId,value=${DEPLOYMENT_ID}" >/dev/null

echo "ok: stage ${STAGE_NAME} now uses deployment ${DEPLOYMENT_ID}"
