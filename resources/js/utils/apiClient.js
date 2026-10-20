/**
 * FlowHub Centralized API Client
 * - Standardized CSRF token management
 * - AbortController request cancellation support
 * - Configurable timeouts
 * - Standardized error extraction & user-friendly error messages
 */

export function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (meta) return meta;
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export class ApiError extends Error {
    constructor(message, status = 0, data = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

/**
 * Execute an asynchronous HTTP request with timeout, abort signal, and error normalization.
 */
export async function request(endpoint, options = {}) {
    const {
        method = 'GET',
        body = null,
        timeout = 35000,
        signal = null,
        headers = {},
    } = options;

    const controller = new AbortController();
    const activeSignal = signal || controller.signal;

    // Timeout handler
    const timeoutId = setTimeout(() => {
        controller.abort(new Error('Request timeout after ' + (timeout / 1000) + 's'));
    }, timeout);

    const defaultHeaders = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
        ...headers,
    };

    if (body && !(body instanceof FormData) && !defaultHeaders['Content-Type']) {
        defaultHeaders['Content-Type'] = 'application/json';
    }

    try {
        const response = await fetch(endpoint, {
            method,
            headers: defaultHeaders,
            body: body && !(body instanceof FormData) && typeof body === 'object' ? JSON.stringify(body) : body,
            signal: activeSignal,
        });

        clearTimeout(timeoutId);

        let data = null;
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            try {
                data = await response.json();
            } catch (jsonErr) {
                data = null;
            }
        } else {
            data = await response.text();
        }

        if (!response.ok) {
            let message = 'Request failed with status ' + response.status;
            if (data && typeof data === 'object') {
                if (data.message) {
                    message = data.message;
                } else if (data.error) {
                    message = data.error;
                } else if (data.errors && typeof data.errors === 'object') {
                    // Extract first validation error
                    const firstKey = Object.keys(data.errors)[0];
                    if (firstKey && data.errors[firstKey][0]) {
                        message = data.errors[firstKey][0];
                    }
                }
            } else if (typeof data === 'string' && data.length < 200 && data.trim()) {
                message = data.trim();
            }

            if (response.status === 403) {
                message = 'Target page or server rejected the connection (403 Forbidden). Try using Browser Engine mode.';
            } else if (response.status === 429) {
                message = 'Rate limit exceeded. Please wait a moment before trying again.';
            } else if (response.status === 504 || response.status === 408) {
                message = 'Connection to the target URL timed out. The server might be slow or blocking crawlers.';
            }

            throw new ApiError(message, response.status, data);
        }

        return data;
    } catch (err) {
        clearTimeout(timeoutId);
        if (err.name === 'AbortError') {
            throw new ApiError('Request was cancelled.', 0, null);
        }
        if (err instanceof ApiError) {
            throw err;
        }
        throw new ApiError(err.message || 'Network error occurred.', 0, null);
    }
}

export const api = {
    get: (url, options = {}) => request(url, { ...options, method: 'GET' }),
    post: (url, body, options = {}) => request(url, { ...options, method: 'POST', body }),
    put: (url, body, options = {}) => request(url, { ...options, method: 'PUT', body }),
    delete: (url, options = {}) => request(url, { ...options, method: 'DELETE' }),
};

export default api;
