const rateLimit = require('express-rate-limit');
const env = require('../config/env');

const heartbeatRateLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: env.heartbeat.rateLimitMax,
  standardHeaders: true,
  legacyHeaders: false,
  message: {
    success: false,
    message: 'Too many heartbeat requests',
    error: 'Rate limit exceeded'
  }
});

module.exports = {
  heartbeatRateLimiter
};
