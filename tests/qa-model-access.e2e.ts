import { test } from '@e2e-dev/web';

// Explicit opt-in: this test requires a working ChatGPT subscription session.
// A model listing is not sufficient proof of authenticated generation.
if (process.env.IERP_QA_MODEL_CHECK === '1') {
    test('ChatGPT agent recognizes the isolated login screen', { timeout: 180000 }, async ({ app, agent }) => {
        await app.open('/admin/login');
        await agent.assert('The current page visibly has fields to enter an email address and a password for signing in.');
    });
}
