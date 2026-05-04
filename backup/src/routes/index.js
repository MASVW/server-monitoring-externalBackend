const express = require('express');
const heartbeatRoutes = require('./heartbeatRoutes');
const statusRoutes = require('./statusRoutes');
const adminIncidentRoutes = require('./adminIncidentRoutes');

const router = express.Router();

router.use('/api/v1', heartbeatRoutes);
router.use('/api/v1', statusRoutes);
router.use('/api/v1/admin', adminIncidentRoutes);

module.exports = router;
