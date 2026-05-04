const dotenv = require('dotenv');

dotenv.config();

const toInt = (value, fallback) => {
  const parsed = Number.parseInt(value, 10);
  return Number.isNaN(parsed) ? fallback : parsed;
};

const toBool = (value, fallback = false) => {
  if (value === undefined || value === null || value === '') {
    return fallback;
  }

  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
};

const env = {
  app: {
    port: toInt(process.env.APP_PORT, 3000),
    env: process.env.APP_ENV || 'development'
  },
  db: {
    host: process.env.DB_HOST || '127.0.0.1',
    port: toInt(process.env.DB_PORT, 3306),
    name: process.env.DB_NAME || 'external_monitoring',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || ''
  },
  heartbeat: {
    hmacSecret: process.env.HEARTBEAT_HMAC_SECRET || '',
    allowedDriftSeconds: toInt(process.env.HEARTBEAT_ALLOWED_DRIFT_SECONDS, 300),
    maxBodySize: process.env.HEARTBEAT_MAX_BODY_SIZE || '100kb',
    rateLimitMax: toInt(process.env.HEARTBEAT_RATE_LIMIT_MAX, 120)
  },
  timeoutChecker: {
    intervalSeconds: toInt(process.env.TIMEOUT_CHECKER_INTERVAL_SECONDS, 60)
  },
  admin: {
    apiToken: process.env.ADMIN_API_TOKEN || ''
  },
  discord: {
    enabled: toBool(process.env.DISCORD_ALERT_ENABLED, false),
    botToken: process.env.DISCORD_BOT_TOKEN || '',
    channelId: process.env.DISCORD_CHANNEL_ID || '',
    alertIntervalSeconds: toInt(process.env.DISCORD_ALERT_INTERVAL_SECONDS, 60),
    apiBaseUrl: process.env.DISCORD_API_BASE_URL || 'https://discord.com/api/v10'
  }
};

module.exports = env;
