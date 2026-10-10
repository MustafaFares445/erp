import { execFileSync, spawn, spawnSync } from 'node:child_process';
import http from 'node:http';
import { basename } from 'node:path';

const root = process.cwd();
const worker = process.env.IERP_QA_WORKER;
if (!worker || !/^worker-0[1-8]$/.test(worker) || basename(root) !== worker
    || !root.replaceAll('\\', '/').includes('/tmp/qa-workspaces/')) {
    throw new Error('Refusing to serve outside an isolated QA worker snapshot.');
}

const port = 8110 + Number(worker.slice(-2));
const windowsPort = 9110 + Number(worker.slice(-2));
const route = execFileSync('ip', ['-4', 'route', 'show', 'default'], { encoding: 'utf8' });
const windowsHost = route.match(/default via (\d+\.\d+\.\d+\.\d+)/)?.[1];
if (!windowsHost) throw new Error('WSL Windows gateway not available.');

const windowsPath = execFileSync('wslpath', ['-w', root], { encoding: 'utf8' }).trim();
const workerScript = windowsPath + '\\scripts\\qa-worker.ps1';

function windowsCommand(action) {
    return spawnSync('powershell.exe', [
        '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
        '-File', workerScript, '-Action', action,
        '-Worker', worker, '-BindHost', windowsHost,
    ], { encoding: 'utf8', timeout: 120000 });
}

// Windows interop may keep powershell.exe alive while its detached PHP child is running.
// Do not block the WSL event loop: the readiness probe will verify the actual server.
const windowsPhp = spawn('powershell.exe', [
    '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
    '-File', workerScript, '-Action', 'serve',
    '-Worker', worker, '-BindHost', windowsHost,
], { stdio: ['ignore', 'pipe', 'pipe'] });
windowsPhp.stdout.on('data', (chunk) => process.stdout.write(chunk));
windowsPhp.stderr.on('data', (chunk) => process.stderr.write(chunk));
windowsPhp.on('error', (error) => {
    console.error('Isolated PHP launcher failed:', error.message);
    shutdown(1);
});
windowsPhp.on('exit', (code) => {
    if (!closing && code !== 0) {
        console.error('Isolated PHP launcher exited with code', code);
        shutdown(1);
    }
});

const server = http.createServer((request, response) => {
    const upstream = http.request({
        host: windowsHost,
        port: windowsPort,
        method: request.method,
        path: request.url,
        headers: { ...request.headers, host: '127.0.0.1:' + port },
    }, (reply) => {
        response.writeHead(reply.statusCode ?? 502, reply.headers);
        reply.pipe(response);
    });
    upstream.on('error', () => {
        if (!response.headersSent) response.writeHead(503);
        response.end('Isolated Laravel is starting');
    });
    request.pipe(upstream);
});
server.on('error', (error) => {
    console.error('QA proxy port collision: ' + error.message);
    shutdown(1);
});
server.listen(port, '127.0.0.1', () => {
    process.stdout.write('ISOLATED_QA_PROXY_READY port=' + port + ' windowsPhpPort=' + windowsPort + '\n');
});

let closing = false;
function shutdown(code = 0) {
    if (closing) return;
    closing = true;
    server.close();
    const result = windowsCommand('stop');
    if (result.status !== 0 || result.error) {
        console.error('Owned PHP cleanup failed:', (result.stderr || result.error?.message || '').slice(0, 800));
        code = 1;
    } else {
        process.stdout.write(result.stdout);
    }
    windowsPhp.kill();
    process.exitCode = code;
    // Stop only the owned bridge process. No unrelated PHP/Windows processes are killed.
}
process.on('SIGTERM', () => shutdown());
process.on('SIGINT', () => shutdown());
