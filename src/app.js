const express = require('express');
const helmet = require('helmet');
const env = require('./config/env');
const routes = require('./routes');
const { errorHandler, notFoundHandler } = require('./middlewares/errorHandler');

const app = express();

app.set('trust proxy', true);
app.use(helmet());
app.use(
  express.json({
    limit: env.heartbeat.maxBodySize,
    verify: (req, _res, buf) => {
      req.rawBody = buf.toString('utf8');
    }
  })
);

app.get('/health', (req, res) => {
  res.status(200).json({
    success: true,
    message: 'OK',
    data: {
      service: 'external-receiver',
      timestamp: new Date().toISOString()
    }
  });
});

app.use(routes);
app.use(notFoundHandler);
app.use(errorHandler);

module.exports = app;
