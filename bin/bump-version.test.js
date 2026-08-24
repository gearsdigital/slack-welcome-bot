const { test } = require('node:test');
const assert = require('node:assert/strict');
const { bumpVersion } = require('./bump-version');

const SAMPLE = `<?php
/**
 * Plugin Name:       Slack Welcome Bot
 * Version:           1.0.0
 * Requires at least: 5.6
 * Tested up to:      7.1
 * Requires PHP:      7.4
 */

define('SWB_PLUGIN_VERSION', '1.0.0');
`;

test('bumps the header Version field', () => {
  const result = bumpVersion(SAMPLE, '1.2.3');
  assert.match(result, /\* Version:\s+1\.2\.3/);
});

test('bumps the SWB_PLUGIN_VERSION constant', () => {
  const result = bumpVersion(SAMPLE, '1.2.3');
  assert.match(result, /define\('SWB_PLUGIN_VERSION', '1\.2\.3'\)/);
});

test('leaves unrelated version-like fields untouched', () => {
  const result = bumpVersion(SAMPLE, '1.2.3');
  assert.match(result, /Requires at least: 5\.6/);
  assert.match(result, /Tested up to:\s+7\.1/);
  assert.match(result, /Requires PHP:\s+7\.4/);
});

test('is idempotent when run twice with different versions', () => {
  const once = bumpVersion(SAMPLE, '1.2.3');
  const twice = bumpVersion(once, '1.2.4');
  assert.match(twice, /\* Version:\s+1\.2\.4/);
  assert.match(twice, /define\('SWB_PLUGIN_VERSION', '1\.2\.4'\)/);
  assert.doesNotMatch(twice, /1\.2\.3/);
});
