const express = require('express');
const { listIncidents } = require('../controllers/incidentController');
const adminAuth = require('../middlewares/adminAuth');

const router = express.Router();

router.use(adminAuth);
router.get('/incidents', listIncidents);

module.exports = router;
