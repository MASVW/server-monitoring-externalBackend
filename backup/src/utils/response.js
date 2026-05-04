const successResponse = (res, { message = 'Success', data = null, meta = undefined, statusCode = 200 }) => {
  const payload = {
    success: true,
    message,
    data
  };

  if (meta !== undefined) {
    payload.meta = meta;
  }

  return res.status(statusCode).json(payload);
};

const errorResponse = (res, { message = 'Request failed', error = 'Request failed', statusCode = 500 }) => {
  return res.status(statusCode).json({
    success: false,
    message,
    error
  });
};

module.exports = {
  successResponse,
  errorResponse
};
