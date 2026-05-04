/* eslint-disable no-console */
const { sendTestAlert, isDiscordConfigured } = require('../services/alertService');

const main = async () => {
  try {
    if (!isDiscordConfigured()) {
      console.error(
        '[discord-test] Discord is not configured. Check DISCORD_ALERT_ENABLED, DISCORD_BOT_TOKEN, and DISCORD_CHANNEL_ID.'
      );
      process.exitCode = 1;
      return;
    }

    await sendTestAlert();
    console.info('[discord-test] Test message sent successfully.');
  } catch (error) {
    console.error(`[discord-test] failed: ${error.message}`);
    process.exitCode = 1;
  }
};

main();
