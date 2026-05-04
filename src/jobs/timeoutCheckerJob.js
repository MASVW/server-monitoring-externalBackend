/* eslint-disable no-console */
const env = require('../config/env');
const { checkTimeouts } = require('../services/timeoutCheckerService');

let timer = null;

const runOnce = async () => {
  try {
    const result = await checkTimeouts();
    console.info(
      `[timeout-checker] checked=${result.checked} marked_down=${result.marked_down} at=${new Date().toISOString()}`
    );
  } catch (error) {
    console.error('[timeout-checker] failed:', error.message);
  }
};

const startTimeoutChecker = () => {
  const intervalMs = env.timeoutChecker.intervalSeconds * 1000;

  if (timer) {
    return;
  }

  timer = setInterval(runOnce, intervalMs);
  timer.unref();

  console.info(`[timeout-checker] started with interval=${env.timeoutChecker.intervalSeconds}s`);
};

const stopTimeoutChecker = () => {
  if (!timer) {
    return;
  }

  clearInterval(timer);
  timer = null;
};

module.exports = {
  startTimeoutChecker,
  stopTimeoutChecker,
  runOnce
};
