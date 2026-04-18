/**
 * Browser-driven E2E tests (Puppeteer).
 *
 * Unlike fullstack.test.js (which hits the API directly with fetch), this
 * suite launches a real Chromium browser, navigates through the frontend UI,
 * interacts via clicks/inputs, and then verifies the resulting state by
 * reading from the backend. This closes the FE ↔ BE E2E gap: a broken API
 * contract or a broken UI wiring will fail here.
 *
 * Requires:
 *   FRONTEND_URL  — e.g. http://frontend:80 (docker-compose e2e profile)
 *   API_BASE_URL  — e.g. http://backend:8000/api/v1
 *
 * Skips automatically if puppeteer is not installed (so the wider suite does
 * not get blocked in environments that have not run `npm install` yet).
 */

let puppeteer;
try {
    puppeteer = require('puppeteer');
} catch (e) {
    puppeteer = null;
}

const FRONTEND_URL = process.env.FRONTEND_URL || 'http://localhost:3000';
const API_BASE_URL = process.env.API_BASE_URL || 'http://localhost:8000/api/v1';

// Skip the browser-driven suite unless we have BOTH puppeteer and a
// Chromium binary to drive. The Docker image deliberately omits
// Chromium (installing it wedges the build on networks that can't
// reach deb.debian.org / storage.googleapis.com). When PUPPETEER_
// EXECUTABLE_PATH is set to an existing file, the suite runs; otherwise
// it skips so `fullstack.test.js` can still report real API failures
// instead of the entire e2e phase dying on a missing browser.
function chromiumAvailable() {
    if (!puppeteer) return false;
    const exePath = process.env.PUPPETEER_EXECUTABLE_PATH;
    if (!exePath) return false;
    try {
        // eslint-disable-next-line global-require
        require('fs').accessSync(exePath);
        return true;
    } catch (_) {
        return false;
    }
}

const maybeDescribe = chromiumAvailable() ? describe : describe.skip;

maybeDescribe('Browser-driven E2E: login → orders flow', () => {
    let browser;
    let page;

    beforeAll(async () => {
        const launchOpts = {
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
            ],
        };
        // Honor PUPPETEER_EXECUTABLE_PATH explicitly. The Docker image
        // installs Chromium via apt and points this at /usr/bin/chromium
        // to avoid puppeteer's CDN-download path, which was timing out
        // the image build. Passing `executablePath` is the documented
        // way to override the bundled browser — relying on env-var
        // auto-detection is inconsistent across puppeteer versions.
        if (process.env.PUPPETEER_EXECUTABLE_PATH) {
            launchOpts.executablePath = process.env.PUPPETEER_EXECUTABLE_PATH;
        }
        browser = await puppeteer.launch(launchOpts);
        page = await browser.newPage();
        page.setDefaultTimeout(15000);
    });

    afterAll(async () => {
        if (browser) await browser.close();
    });

    async function apiRequest(method, path, body, token) {
        const url = API_BASE_URL + (path.startsWith('/') ? path : '/' + path);
        const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (token) headers['Authorization'] = 'Bearer ' + token;
        const opts = { method: method.toUpperCase(), headers };
        if (body && method !== 'GET') opts.body = JSON.stringify(body);
        const res = await fetch(url, opts);
        const data = res.headers.get('content-type')?.includes('json') ? await res.json() : null;
        return { status: res.status, body: data };
    }

    test('login form renders and logs the user in', async () => {
        await page.goto(FRONTEND_URL + '/#/login', { waitUntil: 'networkidle0' });

        await page.waitForSelector('#login-username');
        await page.waitForSelector('#login-password');

        await page.type('#login-username', 'admin');
        await page.type('#login-password', 'Demo12345678!');

        // Wait for store/workstation options to populate (public bootstrap
        // endpoints), then select the first available option.
        await page.waitForFunction(
            () => document.querySelectorAll('#login-store option').length > 1,
            { timeout: 10000 }
        );
        await page.select('#login-store', '1');
        await page.waitForFunction(
            () => document.querySelectorAll('#login-workstation option').length > 1,
            { timeout: 10000 }
        );
        await page.select('#login-workstation', '1');

        const submit = await page.$('#login-submit-btn, button[type="submit"], .login-submit');
        if (submit) {
            await submit.click();
        } else {
            // Fallback: submit the enclosing form
            await page.$eval('form', (f) => f.requestSubmit ? f.requestSubmit() : f.submit());
        }

        // After login the router redirects to dashboard. Assert the hash
        // changed, then confirm the store retained a token.
        await page.waitForFunction(
            () => window.location.hash.includes('dashboard') || window.location.hash.includes('orders'),
            { timeout: 10000 }
        );
        const token = await page.evaluate(() => localStorage.getItem('token'));
        expect(token).toBeTruthy();
    });

    test('created orders become visible in the orders page', async () => {
        // Seed an order via the backend, then verify the UI actually shows it.
        const loginResp = await apiRequest('POST', '/auth/login', {
            username: 'frontdesk1',
            password: 'Demo12345678!',
            store_id: 1,
            workstation_id: 1,
        });
        expect(loginResp.status).toBe(200);
        const fdToken = loginResp.body?.data?.token;
        expect(fdToken).toBeTruthy();

        const marker = 'Browser E2E ' + Date.now();
        const orderResp = await apiRequest('POST', '/orders', {
            customer_name: marker,
            channel: 'front_desk',
            items: [{ service_code: 'SVC-001', service_name: 'Svc', qty: 1, unit_price: 25.00 }],
        }, fdToken);
        expect([200, 201]).toContain(orderResp.status);
        expect(orderResp.body.success).toBe(true);

        // Replace the session in the browser with the front-desk token so the
        // orders list shows the order we just created under the right role.
        await page.evaluate((tok) => {
            localStorage.setItem('token', tok);
            localStorage.setItem('roles', JSON.stringify(['front_desk']));
        }, fdToken);

        await page.goto(FRONTEND_URL + '/#/orders', { waitUntil: 'networkidle0' });

        // Wait for the orders table to render at least one row.
        await page.waitForSelector('.layui-table tbody tr', { timeout: 15000 });

        const bodyText = await page.evaluate(() => document.body.innerText);
        expect(bodyText).toContain(marker);

        // The canonical frontend field mapping renders total_amount via
        // formatUSD(o.total_amount) → "$27.00" for a $25 item + 8% tax.
        // We only check the "$" prefix to avoid locale-specific brittleness.
        expect(bodyText).toMatch(/\$\d+\.\d{2}/);
    });
});
