/**
 * Experiment Variant Runtime Consumption (F-002)
 *
 * Proves that the kiosk page actually reads the runtime assignment returned
 * by GET /api/v1/experiments/:id/assignment and applies the variant to the
 * rendered UI. Covers:
 *   - treatment variant: banner rendered, variant attribute set
 *   - control variant: default experience, no banner
 *   - holdout: default experience, holdout attribute set
 *   - endpoint failure: falls back to control (no thrown error)
 *   - no experiment configured: no backend call, default experience
 *
 * This goes beyond the admin UI coverage at frontend/src/pages/admin.js
 * by exercising the user-facing consumption path end to end.
 */

const localStorageMock = (function () {
    let s = {};
    return {
        getItem: function (k) { return s[k] || null; },
        setItem: function (k, v) { s[k] = String(v); },
        removeItem: function (k) { delete s[k]; },
        clear: function () { s = {}; },
    };
})();
Object.defineProperty(global, 'localStorage', { value: localStorageMock, writable: true });
global.fetch = jest.fn();

const storeModule = require('../../src/store/index');
const api = require('../../src/services/api');
const kiosk = require('../../src/pages/kiosk');

function mockAssignmentResponse(variant, isHoldout) {
    fetch.mockImplementationOnce(function () {
        return Promise.resolve({
            ok: true,
            status: 200,
            json: function () {
                return Promise.resolve({
                    success: true,
                    data: { variant: variant, is_holdout: !!isHoldout },
                    request_id: 'test',
                });
            },
        });
    });
}

async function flushPromises() {
    // Drain the full microtask + timer queue. The assignment chain is
    //   fetch() → response.json() → api.get wrapper → experiments.getAssignment
    //   → kiosk.js applyVariantToContainer
    // which is several microtask hops; a single setTimeout(0) flush plus a
    // few Promise.resolve ticks reliably settles all of them.
    await new Promise(function (resolve) { setTimeout(resolve, 0); });
    await Promise.resolve();
    await Promise.resolve();
}

describe('Kiosk runtime variant consumption (F-002)', () => {
    let container;

    beforeEach(() => {
        storeModule.clear();
        storeModule.setToken('test-token');
        storeModule.setRoles(['customer']);
        api.clearInflight();
        fetch.mockReset();
        localStorage.clear();
        if (typeof window !== 'undefined') {
            delete window.__KIOSK_EXPERIMENT_ID__;
        }
        document.body.innerHTML = '<div id="page-inner"></div>';
        container = document.getElementById('page-inner');
    });

    test('treatment variant hits the assignment endpoint and renders the promo banner', async () => {
        localStorage.setItem('kiosk_experiment_id', '42');
        mockAssignmentResponse('treatment', false);

        kiosk.render(container);
        await flushPromises();

        // The kiosk called the runtime assignment endpoint, not the admin listing.
        const calledUrls = fetch.mock.calls.map(c => c[0]);
        const assignmentCall = calledUrls.find(u => u.indexOf('/experiments/42/assignment') !== -1);
        expect(assignmentCall).toBeDefined();
        expect(assignmentCall).not.toMatch(/\/assignments(\?|$)/);

        // Variant is reflected on the container as an attribute the rest of the
        // UI (and CSS) can branch on.
        expect(container.getAttribute('data-kiosk-variant')).toBe('treatment');
        expect(container.getAttribute('data-kiosk-holdout')).toBe('false');

        // Treatment-specific UI element is rendered.
        const banner = container.querySelector('[data-variant-banner="treatment"]');
        expect(banner).not.toBeNull();
        expect(banner.textContent).toMatch(/special pricing/i);
    });

    test('control variant renders the default experience with no treatment banner', async () => {
        localStorage.setItem('kiosk_experiment_id', '42');
        mockAssignmentResponse('control', false);

        kiosk.render(container);
        await flushPromises();

        expect(container.getAttribute('data-kiosk-variant')).toBe('control');
        expect(container.getAttribute('data-kiosk-holdout')).toBe('false');
        expect(container.querySelector('[data-variant-banner="treatment"]')).toBeNull();
    });

    test('holdout users get the default experience regardless of variant name', async () => {
        localStorage.setItem('kiosk_experiment_id', '42');
        // Backend may still emit a variant for holdout users; the UI must
        // ignore it and render control.
        mockAssignmentResponse('treatment', true);

        kiosk.render(container);
        await flushPromises();

        expect(container.getAttribute('data-kiosk-holdout')).toBe('true');
        expect(container.querySelector('[data-variant-banner="treatment"]')).toBeNull();
    });

    test('assignment endpoint failure falls back to control and does not break the page', async () => {
        localStorage.setItem('kiosk_experiment_id', '42');
        fetch.mockImplementationOnce(function () {
            return Promise.resolve({
                ok: false,
                status: 500,
                json: function () { return Promise.resolve({ success: false, message: 'boom' }); },
            });
        });

        kiosk.render(container);
        await flushPromises();

        // Page still rendered; variant defaulted to control.
        expect(container.getAttribute('data-kiosk-variant')).toBe('control');
        expect(container.querySelector('#kiosk-content')).not.toBeNull();
    });

    test('no experiment configured means no assignment call is made', async () => {
        // Neither localStorage nor window global set.
        kiosk.render(container);
        await flushPromises();

        const assignmentCalls = fetch.mock.calls
            .map(c => c[0])
            .filter(u => typeof u === 'string' && u.indexOf('/experiments/') !== -1);
        expect(assignmentCalls).toHaveLength(0);

        // Default experience still renders.
        expect(container.getAttribute('data-kiosk-variant')).toBe('control');
    });
});
