const AppError = require('../utils/appError');
const { successResponse } = require('../utils/response');
const { validateIncidentsQuery } = require('../validators/incidentsQueryValidator');
const { getPaginatedIncidents } = require('../services/incidentService');

const listIncidents = async (req, res, next) => {
  try {
    const parsed = validateIncidentsQuery(req.query);

    if (!parsed.success) {
      throw new AppError('Invalid query parameters', 400, 'Bad Request');
    }

    const { node_id, page, limit, status, event_type } = parsed.data;

    const result = await getPaginatedIncidents({
      filters: {
        node_id,
        status,
        event_type
      },
      page,
      limit
    });

    return successResponse(res, {
      data: result.data,
      meta: result.meta
    });
  } catch (error) {
    return next(error);
  }
};

module.exports = {
  listIncidents
};
