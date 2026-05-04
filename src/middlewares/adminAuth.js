const env = require('../config/env');
const AppError = require('../utils/appError');

const adminAuth = (req, res, next) => {
  if (!env.admin.apiToken) {
    return next();
  }

  const token = req.headers['x-admin-token'];
  if (!token || token !== env.admin.apiToken) {
    return next(new AppError('Unauthorized access', 401, 'Unauthorized'));
  }

  return next();
};

module.exports = adminAuth;
