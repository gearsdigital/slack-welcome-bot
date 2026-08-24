#!/usr/bin/env node
// Bumps the version in the plugin header + SWB_PLUGIN_VERSION constant.
// Called by semantic-release's exec plugin: node bin/bump-version.js <version>
const fs = require('fs');
const path = require('path');

const version = process.argv[2];
if (!version) {
  console.error('Usage: bump-version.js <version>');
  process.exit(1);
}

const file = path.join(__dirname, '..', 'slack-welcome-bot.php');
let contents = fs.readFileSync(file, 'utf8');

contents = contents.replace(/(\* Version:\s*)[\d.]+/, `$1${version}`);
contents = contents.replace(/(define\('SWB_PLUGIN_VERSION',\s*')[\d.]+(')/, `$1${version}$2`);

fs.writeFileSync(file, contents);
console.log(`Bumped plugin version to ${version}`);
