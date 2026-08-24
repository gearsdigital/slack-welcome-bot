#!/usr/bin/env node
/**
 * Bumps the version in the plugin header + SWB_PLUGIN_VERSION constant.
 * Called by semantic-release's exec plugin: node bin/bump-version.js <version>
 */
const fs = require('fs');
const path = require('path');

const PLUGIN_FILE = path.join(__dirname, '..', 'slack-welcome-bot.php');

/**
 * Replaces the plugin header's `Version:` field and the
 * `SWB_PLUGIN_VERSION` constant with a new version string.
 *
 * @param {string} contents - Raw contents of slack-welcome-bot.php.
 * @param {string} version - New version, e.g. "1.2.0".
 * @returns {string} Updated file contents.
 */
function bumpVersion(contents, version) {
  return contents
    .replace(/(\* Version:\s*)[\d.]+/, `$1${version}`)
    .replace(/(define\('SWB_PLUGIN_VERSION',\s*')[\d.]+(')/, `$1${version}$2`);
}

function main() {
  const version = process.argv[2];
  if (!version) {
    console.error('Usage: bump-version.js <version>');
    process.exit(1);
  }

  const contents = fs.readFileSync(PLUGIN_FILE, 'utf8');
  fs.writeFileSync(PLUGIN_FILE, bumpVersion(contents, version));
  console.log(`Bumped plugin version to ${version}`);
}

if (require.main === module) {
  main();
}

module.exports = { bumpVersion };
