const { errorResponse } = require('../utils/response');

const errorHandler = (err, req, res, next) => {
  const statusCode = err.statusCode || 500;
  const message = err.message || 'Request failed';
  const error = err.error || (statusCode >= 500 ? 'Internal server error' : 'Bad Request');

  if (statusCode >= 500) {
    // Avoid exposing sensitive internals in production responses.
    // eslint-disable-next-line no-console
    console.error(`[${new Date().toISOString()}]`, message);
  }

  return errorResponse(res, {
    statusCode,
    message,
    error
  });
};

const notFoundHandler = (req, res) => {
  return errorResponse(res, {
    statusCode: 404,
    message: 'Route not found',
    error: 'Not Found'
  });
};

module.exports = {
  errorHandler,
  notFoundHandler
};
