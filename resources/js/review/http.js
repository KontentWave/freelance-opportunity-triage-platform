const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

export class ReviewHttpError extends Error {
    constructor(status, payload) {
        super(payload?.message ?? "The request could not be completed.");
        this.status = status;
        this.errors = payload?.errors ?? {};
    }
}

export async function reviewRequest(url, options = {}) {
    const response = await fetch(url, {
        credentials: "same-origin",
        ...options,
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            ...(csrfToken ? { "X-CSRF-TOKEN": csrfToken } : {}),
            ...options.headers,
        },
    });
    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        throw new ReviewHttpError(response.status, payload);
    }

    return payload;
}
