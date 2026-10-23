import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const pkg = JSON.parse(read('package.json'));
const lock = JSON.parse(read('package-lock.json'));
const plugin = read('wordpress-plugin/ai-site-manager/ai-site-manager.php');
const readme = read('wordpress-plugin/ai-site-manager/readme.txt');
const { version } = pkg;
const parse = value => {
  assert.match(value, /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/, `Expected a stable semantic version: ${value}`);
  return value.split('.').map(BigInt);
};
const current = parse(version);

for (const [name, value] of [
  ['lockfile version', lock.version],
  ['lockfile root package version', lock.packages[''].version],
  ['WordPress plugin header', plugin.match(/^ \* Version: (.+)$/m)?.[1]],
  ['AISM_VERSION', plugin.match(/define\( 'AISM_VERSION', '([^']+)' \);/)?.[1]],
  ['WordPress stable tag', readme.match(/^Stable tag: (.+)$/m)?.[1]],
]) {
  assert.equal(value, version, `${name} must match package.json (${version})`);
}
assert.ok(readme.includes(`\n= ${version} =\n`), `Missing companion changelog for ${version}`);

// CI supplies existing release tags and the version before the push.
for (const ref of process.argv.slice(2)) {
  const previous = parse(ref.replace(/^v/, ''));
  const difference = current.findIndex((value, index) => value !== previous[index]);
  assert.ok(difference >= 0 && current[difference] > previous[difference],
    `Version ${version} must be greater than ${ref}; bump it before pushing to main`);
}
console.log(`Release versions are synchronized at ${version}`);
