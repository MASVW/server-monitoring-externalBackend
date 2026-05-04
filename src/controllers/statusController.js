const AppError = require('../utils/appError');
const { successResponse } = require('../utils/response');
const { getNodeStatus } = require('../services/statusService');

const getStatus = async (req, res, next) => {
  try {
    const { nodeId } = req.params;

    const status = await getNodeStatus(nodeId);

    if (!status) {
      throw new AppError('Node not found', 404, 'Not Found');
    }

    return successResponse(res, {
      data: status
    });
  } catch (error) {
    return next(error);
  }
};

module.exports = {
  getStatus
};
