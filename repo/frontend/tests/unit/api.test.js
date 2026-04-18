/**
 * API Service Unit Tests
 * Tests HTTP client with fetch mocking, auth header injection, deduplication, error normalization.
 */

// The api module reads process.env.API_BASE_URL at require time to set
// BASE_URL. Inside the test-frontend container, docker-compose sets this
// to an absolute URL for integration flows, which would make unit tests
// that assert the default `/api/v1` fail. Clear it before the require so
// this unit test sees the default-path behavior regardless of how it is
// invoked (host, Docker, CI).
delete process.env.API_BASE_URL;

// Mock localStorage before requiring store
const localStorageMock = (function() {
    let store = {};
    return {
        getItem: function(key) { return store[key] || null; },
        setItem: function(key, value) { store[key] = String(value); },
        removeItem: function(key) { delete store[key]; },
        clear: function() { store = {}; },
    };
})();
Object.defineProperty(global, 'localStorage', { value: localStorageMock });

const store = require('../../src/store/index');
const api = require('../../src/services/api');

// Mock global fetch
global.fetch = jest.fn();

describe('API Service', () => {
    beforeEach(() => {
        store.clear();
        api.clearInflight();
        fetch.mockClear();
    });

    describe('buildUrl', () => {
        test('BASE_URL is set to /api/v1', () => {
            expect(api.BASE_URL).toBe('/api/v1');
        });
    });

    describe('GET requests', () => {
        test('sends GET request to correct URL', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true, data: [] }),
            });

            await api.get('/orders');
            expect(fetch).toHaveBeenCalledWith(
                '/api/v1/orders',
                expect.objectContaining({ method: 'GET' })
            );
        });

        test('appends query params to URL', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await api.get('/orders', { status: 'draft', page: 1 });
            const calledUrl = fetch.mock.calls[0][0];
            expect(calledUrl).toContain('status=draft');
            expect(calledUrl).toContain('page=1');
        });

        test('filters out null and undefined params', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await api.get('/orders', { status: 'draft', extra: null, foo: undefined });
            const calledUrl = fetch.mock.calls[0][0];
            expect(calledUrl).toContain('status=draft');
            expect(calledUrl).not.toContain('extra');
            expect(calledUrl).not.toContain('foo');
        });
    });

    describe('POST requests', () => {
        test('sends POST with JSON body', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await api.post('/orders', { customer_name: 'John' });
            const options = fetch.mock.calls[0][1];
            expect(options.method).toBe('POST');
            expect(JSON.parse(options.body)).toEqual({ customer_name: 'John' });
        });
    });

    describe('PUT requests', () => {
        test('sends PUT with JSON body', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await api.put('/orders/1', { status: 'confirmed' });
            const options = fetch.mock.calls[0][1];
            expect(options.method).toBe('PUT');
        });
    });

    describe('PATCH requests', () => {
        test('sends PATCH with JSON body', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await api.patch('/orders/1', { customer_name: 'Jane' });
            const options = fetch.mock.calls[0][1];
            expect(options.method).toBe('PATCH');
        });
    });

    describe('DELETE requests', () => {
        test('sends DELETE request', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await api.del('/orders/1');
            const options = fetch.mock.calls[0][1];
            expect(options.method).toBe('DELETE');
        });
    });

    describe('Auth header injection', () => {
        test('includes Authorization header when token exists', async () => {
            store.setToken('test-token-123');
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await api.get('/auth/me');
            const options = fetch.mock.calls[0][1];
            expect(options.headers['Authorization']).toBe('Bearer test-token-123');
        });

        test('does not include Authorization when no token', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await api.get('/orders');
            const options = fetch.mock.calls[0][1];
            expect(options.headers['Authorization']).toBeUndefined();
        });
    });

    describe('Error handling', () => {
        test('throws error with status for non-ok responses', async () => {
            fetch.mockResolvedValueOnce({
                ok: false,
                status: 401,
                json: () => Promise.resolve({ message: 'Unauthorized', error_code: 'UNAUTHORIZED' }),
            });

            await expect(api.get('/orders')).rejects.toThrow('Unauthorized');
        });

        test('throws error with status 0 for network errors', async () => {
            fetch.mockRejectedValueOnce(new Error('Network error'));

            try {
                await api.get('/orders');
                fail('Should have thrown');
            } catch (err) {
                expect(err.status).toBe(0);
                expect(err.message).toBe('Network error');
            }
        });

        test('handles 204 No Content response', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 204,
            });

            const result = await api.get('/some-endpoint');
            expect(result.data).toBeNull();
            expect(result.status).toBe(204);
        });
    });

    describe('Duplicate submit prevention', () => {
        test('deduplicates concurrent POST requests', async () => {
            let resolvePromise;
            const promise = new Promise((resolve) => { resolvePromise = resolve; });
            fetch.mockReturnValueOnce(promise);

            const req1 = api.post('/orders', { customer_name: 'John' });
            const req2 = api.post('/orders', { customer_name: 'John' });

            // Same promise returned for duplicate
            expect(req1).toBe(req2);

            resolvePromise({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await req1;
            expect(fetch).toHaveBeenCalledTimes(1);
        });

        test('GET requests skip deduplication guard', async () => {
            fetch.mockResolvedValue({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await api.get('/orders');
            await api.get('/orders');
            expect(fetch).toHaveBeenCalledTimes(2);
        });
    });

    describe('Content-Type header', () => {
        test('sets Content-Type to application/json', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });

            await api.get('/orders');
            const options = fetch.mock.calls[0][1];
            expect(options.headers['Content-Type']).toBe('application/json');
        });
    });

    describe('clearInflight', () => {
        test('clears all in-flight request references', () => {
            // Just verify it does not throw
            expect(() => api.clearInflight()).not.toThrow();
        });
    });
});
