// Serves a built Fleetbase console the way its nginx image does: files from the dist
// directory, and index.html for any other path so the Ember router can take it.
// Usage: node serve-console.mjs <dist directory> [port]
import { createServer } from 'node:http';
import { createReadStream, statSync } from 'node:fs';
import { extname, join, normalize, resolve, sep } from 'node:path';

const root = resolve(process.argv[2] ?? 'dist');
const port = Number(process.argv[3] ?? 4200);
const types = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.ico': 'image/x-icon',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
    '.map': 'application/json; charset=utf-8',
};

function fileFor(urlPath) {
    const decoded = decodeURIComponent(urlPath.split('?')[0]);
    const candidate = normalize(join(root, decoded));
    if (candidate !== root && !candidate.startsWith(root + sep)) {
        return null;
    }
    try {
        return statSync(candidate).isFile() ? candidate : null;
    } catch {
        return null;
    }
}

createServer((request, response) => {
    let file = null;
    try {
        file = fileFor(request.url ?? '/');
    } catch {
        response.writeHead(400).end();
        return;
    }
    const path = file ?? join(root, 'index.html');
    response.writeHead(200, { 'Content-Type': types[extname(path)] ?? 'application/octet-stream' });
    createReadStream(path).pipe(response);
}).listen(port, '127.0.0.1', () => console.log(`console on http://127.0.0.1:${port}`));
