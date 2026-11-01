const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const run = mode => JSON.parse(execFileSync(process.env.SAHDEV_PHP || 'php', [path.join(__dirname, 'run_copilot_ajax_fixture.php'), mode], { encoding: 'utf8' }));
const message = run('message');
assert.equal(message.success, true, message.message || message.error);
assert.equal(message.reply, 'Copilot works without Ticket AI');
assert.ok(message.session_uuid.startsWith('copilot_'));
for (const mode of ['missing_token', 'invalid_token']) {
    const result = run(mode);
    assert.equal(result.status, 'error');
    assert.match(result.message, /Invalid CSRF token/);
}
assert.match(run('forbidden').message, /Access Denied/);
assert.match(run('empty_message').message, /Message cannot be empty/);
assert.equal(run('execute').success, true, 'Confirmed operation dispatches without Ticket AI');
assert.equal(run('rollback').success, true, 'Rollback dispatches without Ticket AI');
console.log('PASS: 7 production AJAX scenarios (messages, missing/invalid CSRF, permissions, empty input, execution, rollback).');
