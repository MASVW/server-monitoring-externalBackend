class AppError extends Error {
  constructor(message, statusCode = 500, error = 'Internal server error') {
    super(message);
    this.statusCode = statusCode;
    this.error = error;
  }
}

module.exports = AppError;
