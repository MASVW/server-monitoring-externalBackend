const express = require('express');
const { createHeartbeat } = require('../controllers/heartbeatController');
const { heartbeatRateLimiter } = require('../middlewares/rateLimiter');

const router = express.Router();

router.post('/heartbeat', heartbeatRateLimiter, createHeartbeat);

module.exports = router;
