# External Receiver (Server Monitoring)

Backend service that receives signed heartbeat payloads from internal node(s), stores latest state in MySQL, evaluates timeout-based downtime, and exposes status/incident APIs.

## Requirements

- Node.js 20+
- MySQL 8+

## Setup

1. Copy env template:

```bash
cp .env.example .env
```

2. Install dependencies:

```bash
npm install
```

3. Run migrations:

```bash
npm run migrate
```

4. Start service:

```bash
npm run start
```

## Environment Variables

- `APP_PORT`
- `APP_ENV`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `HEARTBEAT_HMAC_SECRET`
- `HEARTBEAT_ALLOWED_DRIFT_SECONDS` (default `300`)
- `HEARTBEAT_MAX_BODY_SIZE` (default `100kb`)
- `TIMEOUT_CHECKER_INTERVAL_SECONDS` (default `60`)
- `ADMIN_API_TOKEN` (optional)
- `DISCORD_ALERT_ENABLED` (default `false`)
- `DISCORD_BOT_TOKEN`
- `DISCORD_CHANNEL_ID`
- `DISCORD_ALERT_INTERVAL_SECONDS` (default `60`)
- `DISCORD_API_BASE_URL` (default `https://discord.com/api/v10`)

## API Routes

- `POST /api/v1/heartbeat`
- `GET /api/v1/status/:nodeId`
- `GET /api/v1/admin/incidents`
- `GET /health`

## HMAC Signature (internal backend)

Signature string source:

```text
raw_request_body + x-timestamp
```

Signature algorithm:

```text
HMAC SHA256 hex digest
```

Node.js example:

```js
const crypto = require('crypto');

const rawBody = JSON.stringify(payload);
const timestamp = new Date().toISOString();
const signature = crypto
  .createHmac('sha256', process.env.HEARTBEAT_HMAC_SECRET)
  .update(`${rawBody}${timestamp}`)
  .digest('hex');
```

Headers to send:

- `x-node-id`
- `x-timestamp`
- `x-signature`

## Timeout Checker

- Runs automatically with server startup.
- Interval from `TIMEOUT_CHECKER_INTERVAL_SECONDS`.
- Manual single run:

```bash
npm run timeout:check
```

## Discord Alerts

- Transition alerts are sent on status changes (`degraded`, `down`, `recovered`).
- Reminder alerts are sent every `DISCORD_ALERT_INTERVAL_SECONDS` while node is `down`, `degraded`, or has non-`online` service states in the last summary.
- Reminder runner also starts automatically with the server.
- Quick connection test:

```bash
npm run discord:test
```

- Manual single reminder run:

```bash
npm run discord:reminder:once
```

## Quick Incident Flow

1. Send heartbeat with status `ok`.
2. Send heartbeat with status `degraded` from same node.
3. Send heartbeat with status `ok` again.
4. Stop heartbeat until threshold passes.
5. Run checker or wait for interval.
6. Read incidents:

```bash
curl "http://localhost:3000/api/v1/admin/incidents?node_id=node-01&page=1&limit=20"
```
