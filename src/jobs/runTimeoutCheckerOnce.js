/* eslint-disable no-console */
const { sequelize } = require('../models');
const { runOnce } = require('./timeoutCheckerJob');

const main = async () => {
  try {
    await sequelize.authenticate();
    await runOnce();
  } catch (error) {
    console.error('[timeout-checker-cli] failed:', error.message);
    process.exitCode = 1;
  } finally {
    await sequelize.close();
  }
};

main();
