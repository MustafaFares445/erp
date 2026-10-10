import type { E2EConfig } from 'e2e';
import { web } from '@e2e-dev/web';
import { chatgpt } from 'e2e/oauth/chatgpt';
import { basename, join } from 'node:path';
import { existsSync, readFileSync } from 'node:fs';

const root = process.cwd().replaceAll('\\', '/');
const worker = basename(root);
if (!/^worker-0[1-8]$/.test(worker) || !root.includes('/tmp/qa-workspaces/')) {
    throw new Error('E2E must run inside an isolated QA worker snapshot.');
}
const port = 8110 + Number(worker.slice(-2));
const url = 'http://127.0.0.1:' + port;
const qaSecretDir = join(process.cwd(), 'tmp', 'qa-secrets');

export default {
    agents: {
        default: {
            model: chatgpt('gpt-6-luna'),
            judgmentTimeout: 90000,
            system: 'Verify observable ERP behavior; do not reveal authentication material.',
        },
    },
    credentials: {
        admin: {
            username: existsSync(join(qaSecretDir, 'admin-username'))
                ? readFileSync(join(qaSecretDir, 'admin-username'), 'utf8').trim()
                : (process.env.IERP_QA_ADMIN_EMAIL ?? 'unset@invalid.test'),
            password: () => existsSync(join(qaSecretDir, 'admin-password'))
                ? readFileSync(join(qaSecretDir, 'admin-password'), 'utf8').trim()
                : (process.env.IERP_QA_ADMIN_PASSWORD ?? ''),
        },
    },
    targets: [{
        engine: web(),
        app: {
            url,
            readyUrl: url + '/admin/login',
            command: {
                executable: 'node',
                args: ['scripts/e2e-serve-wsl.mjs'],
                env: { IERP_QA_WORKER: worker },
                startupTimeout: 120000,
                shutdownTimeout: 30000,
                log: '.e2e/logs/app.log',
            },
        },
    }],
    workers: 1,
} satisfies E2EConfig;
