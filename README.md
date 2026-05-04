# External Receiver (Laravel 12)

External monitoring receiver that accepts signed heartbeat payloads from internal nodes, stores latest state in MySQL, evaluates timeout incidents, and exposes status + incident APIs.

## Repository Layout

- Laravel app is at repository root.
- Previous Node.js implementation is preserved in `/backup`.

## Requirements

- PHP 8.3+
- Composer 2+
- MySQL 8+

## Setup

1. Copy environment:

```bash
cp .env.example .env
```

2. Install dependencies:

```bash
composer install
```

3. Generate app key:

```bash
php artisan key:generate
```

4. Run migrations:

```bash
php artisan migrate
```

5. Run locally:

```bash
php artisan serve --host=0.0.0.0 --port=${APP_PORT:-8000}
```

## Environment Variables

- `APP_PORT`
- `APP_ENV`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `HEARTBEAT_HMAC_SECRET`
- `HEARTBEAT_ALLOWED_DRIFT_SECONDS` (default `300`)
- `HEARTBEAT_MAX_BODY_SIZE` (default `100kb`)
- `HEARTBEAT_RATE_LIMIT_MAX` (default `120`)
- `TIMEOUT_CHECKER_INTERVAL_SECONDS` (default `60`)
- `ADMIN_API_TOKEN` (optional)
- `DISCORD_ALERT_ENABLED`
- `DISCORD_BOT_TOKEN`
- `DISCORD_CHANNEL_ID`
- `DISCORD_ALERT_INTERVAL_SECONDS` (default `60`)
- `DISCORD_API_BASE_URL` (default `https://discord.com/api/v10`)

## API Routes

- `POST /api/v1/heartbeat`
- `GET /api/v1/status/{nodeId}`
- `GET /api/v1/admin/incidents`
- `GET /health`

## JSON Response Contract

Success:

```json
{
  "success": true,
  "message": "...",
  "data": {},
  "meta": {}
}
```

Error:

```json
{
  "success": false,
  "message": "...",
  "error": "..."
}
```

## HMAC Signature

Signature source string:

```text
raw_request_body + x-timestamp
```

Algorithm:

```text
HMAC SHA256 (hex)
```

PHP sender example:

```php
$rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
$timestamp = gmdate('Y-m-d\\TH:i:s.000\\Z');
$signature = hash_hmac('sha256', $rawBody.$timestamp, getenv('HEARTBEAT_HMAC_SECRET'));
```

Headers:

- `x-node-id`
- `x-timestamp`
- `x-signature`

## Example Heartbeat cURL

```bash
TIMESTAMP="$(date -u +"%Y-%m-%dT%H:%M:%S.000Z")"
BODY='{"node_id":"node-01","timestamp":"'"$TIMESTAMP"'","overall_status":"ok","host":{"cpu":{},"memory":{},"disk":{},"uptime":123456},"services":[{"name":"api-server","status":"online","pm_id":0,"restart_count":2,"cpu":0.5,"memory_mb":120.3}],"problems":[]}'
SIGNATURE=$(php -r '$body=$argv[1];$ts=$argv[2];$secret=getenv("HEARTBEAT_HMAC_SECRET");echo hash_hmac("sha256", $body.$ts, $secret);' "$BODY" "$TIMESTAMP")

curl -X POST "http://localhost:${APP_PORT:-8000}/api/v1/heartbeat" \
  -H "Content-Type: application/json" \
  -H "x-node-id: node-01" \
  -H "x-timestamp: $TIMESTAMP" \
  -H "x-signature: $SIGNATURE" \
  -d "$BODY"
```

## Operational Commands

Run timeout checker once:

```bash
php artisan monitor:check-timeouts
```

Run Discord reminders once:

```bash
php artisan monitor:send-discord-reminders
```

Send Discord test message:

```bash
php artisan monitor:discord-test
```

## Scheduler (cPanel)

Use one cron entry:

```bash
* * * * * php /home/<user>/<app>/artisan schedule:run >> /dev/null 2>&1
```

Scheduled tasks:

- `monitor:check-timeouts` every minute
- `monitor:send-discord-reminders` every minute

## Verify End-to-End Flow

1. Send heartbeat with `overall_status=ok`.
2. Send heartbeat with `overall_status=degraded`.
3. Send heartbeat with `overall_status=ok` again.
4. Stop heartbeat longer than timeout threshold.
5. Run `php artisan monitor:check-timeouts` (or wait scheduler).
6. Read incidents:

```bash
curl "http://localhost:${APP_PORT:-8000}/api/v1/admin/incidents?node_id=node-01&page=1&limit=20"
```
