var api = require('./api');

/**
 * Experiment runtime client.
 *
 * Fetches the caller's sticky variant assignment for a given experiment id
 * from GET /api/v1/experiments/:id/assignment. Pages use this on load to
 * decide which variant to render; callers in the holdout or in a stopped
 * experiment get the default/control experience.
 *
 * The backend contract pins:
 *   { variant: string | null, is_holdout: boolean, experiment_status?: string }
 *
 * This helper never throws to its caller — a network or auth failure falls
 * back to the control experience so a broken assignment endpoint can't
 * take a user-facing page offline.
 */

var CONTROL = { variant: 'control', is_holdout: false, experiment_id: null };

function controlFallback(experimentId) {
  return {
    experiment_id: experimentId,
    variant: 'control',
    is_holdout: false,
  };
}

/**
 * Fetch the runtime variant assignment for the given experiment.
 * Always resolves — never rejects — so the UI can consume it unconditionally.
 */
function getAssignment(experimentId) {
  if (!experimentId) {
    return Promise.resolve(controlFallback(null));
  }

  return api.get('experiments/' + experimentId + '/assignment').then(function (res) {
    var payload = res && res.data ? res.data : {};
    return {
      experiment_id: experimentId,
      variant: payload.variant == null ? 'control' : payload.variant,
      is_holdout: !!payload.is_holdout,
      experiment_status: payload.experiment_status || null,
    };
  }).catch(function () {
    return controlFallback(experimentId);
  });
}

module.exports = {
  getAssignment: getAssignment,
  CONTROL: CONTROL,
};
