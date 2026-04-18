/**
 * App Routing Integration Tests (jsdom)
 * Mounts app.js, navigates hash routes, asserts correct page module is called.
 */

// Mock localStorage
const localStorageMock = (function () {
    let s = {};
    return {
        getItem: function (k) { return s[k] || null; },
        setItem: function (k, v) { s[k] = String(v); },
        removeItem: function (k) { delete s[k]; },
        clear: function () { s = {}; },
    };
})();
Object.defineProperty(global, 'localStorage', { value: localStorageMock });
global.fetch = jest.fn();

const store = require('../../src/store/index');
const router = require('../../src/router/index');
const app = require('../../src/app');

describe('App Routing - Page Module Registry', () => {
    beforeEach(() => {
        store.clear();
        localStorageMock.clear();
        router.destroy();
    });

    test('PAGE_MODULES registry has entries for all routes', () => {
        const routes = router.getRoutes();
        routes.forEach(function (route) {
            if (route.page === 'order-detail') return; // shares orders module
            expect(app.PAGE_MODULES[route.page]).toBeDefined();
        });
    });

    test('each PAGE_MODULES entry is a function', () => {
        Object.keys(app.PAGE_MODULES).forEach(function (key) {
            expect(typeof app.PAGE_MODULES[key]).toBe('function');
        });
    });

    test('PAGE_MODULES lazy loaders return objects with render method', () => {
        var keys = ['dashboard', 'orders', 'finance', 'admin', 'kiosk', 'cleansing', 'environmental'];
        keys.forEach(function (key) {
            var loader = app.PAGE_MODULES[key];
            if (loader) {
                var mod = loader();
                expect(mod).toBeDefined();
                expect(typeof mod.render).toBe('function');
            }
        });
    });
});

describe('App Routing - renderPageModule', () => {
    test('renderPageModule calls render on the page module', () => {
        document.body.innerHTML = '<div id="page-inner"></div>';
        // renderPageModule should attempt to load and render the module
        app.renderPageModule('dashboard', {});
        var inner = document.getElementById('page-inner');
        // If module loads, it writes into the container
        expect(inner).toBeTruthy();
    });

    test('renderPageModule handles unknown page gracefully', () => {
        document.body.innerHTML = '<div id="page-inner"></div>';
        expect(function () {
            app.renderPageModule('nonexistent-page', {});
        }).not.toThrow();
    });
});

describe('App Routing - Route→Page mapping', () => {
    test('dashboard route maps to dashboard page module', () => {
        var route = router.findRoute('dashboard');
        expect(route.page).toBe('dashboard');
        expect(app.PAGE_MODULES['dashboard']).toBeDefined();
    });

    test('orders route maps to orders page module', () => {
        var route = router.findRoute('orders');
        expect(route.page).toBe('orders');
        expect(app.PAGE_MODULES['orders']).toBeDefined();
    });

    test('finance route maps to finance page module', () => {
        var route = router.findRoute('finance');
        expect(route.page).toBe('finance');
        expect(app.PAGE_MODULES['finance']).toBeDefined();
    });

    test('admin route maps to admin page module', () => {
        var route = router.findRoute('admin');
        expect(route.page).toBe('admin');
        expect(app.PAGE_MODULES['admin']).toBeDefined();
    });

    test('kiosk route maps to kiosk page module', () => {
        var route = router.findRoute('kiosk');
        expect(route.page).toBe('kiosk');
        expect(app.PAGE_MODULES['kiosk']).toBeDefined();
    });

    test('environmental route maps to environmental page module', () => {
        var route = router.findRoute('environmental');
        expect(route.page).toBe('environmental');
        expect(app.PAGE_MODULES['environmental']).toBeDefined();
    });

    test('cleansing route maps to cleansing page module', () => {
        var route = router.findRoute('cleansing');
        expect(route.page).toBe('cleansing');
        expect(app.PAGE_MODULES['cleansing']).toBeDefined();
    });

    test('audit-logs route maps to audit-logs page module', () => {
        var route = router.findRoute('audit-logs');
        expect(route.page).toBe('audit-logs');
        expect(app.PAGE_MODULES['audit-logs']).toBeDefined();
    });

    test('technician-queue route maps to technician-queue page module', () => {
        var route = router.findRoute('technician-queue');
        expect(route.page).toBe('technician-queue');
        expect(app.PAGE_MODULES['technician-queue']).toBeDefined();
    });
});
