// Same-origin JSON client. Auth is the Laravel session cookie; writes carry the
// CSRF token from the host page. Money is always integer paise on the wire.

export class ApiError extends Error {
  status: number;
  fieldErrors: Record<string, string[]>;
  constructor(status: number, message: string, fieldErrors: Record<string, string[]> = {}) {
    super(message);
    this.status = status;
    this.fieldErrors = fieldErrors;
  }
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

type Query = Record<string, string | number | boolean | null | undefined>;

export function withQuery(url: string, query?: Query): string {
  if (!query) return url;
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value === null || value === undefined || value === '') continue;
    params.set(key, String(value));
  }
  const qs = params.toString();
  return qs ? `${url}${url.includes('?') ? '&' : '?'}${qs}` : url;
}

/** fetch() that turns a dropped connection into a plain message instead of "Failed to fetch". */
async function send(url: string, init: RequestInit, offlineMessage: string): Promise<Response> {
  try {
    return await fetch(url, init);
  } catch (reason) {
    if (reason instanceof DOMException && reason.name === 'AbortError') throw reason;
    throw new ApiError(0, offlineMessage);
  }
}

async function handle<T>(response: Response): Promise<T> {
  if (response.ok) {
    if (response.status === 204) return undefined as T;
    return (await response.json()) as T;
  }
  let body: {message?: string; errors?: Record<string, string[]>} = {};
  try {
    body = await response.json();
  } catch {
    // Non-JSON error page; fall through to a status-based message.
  }
  if (response.status === 401) {
    window.location.assign('/login');
    throw new ApiError(401, 'Your session has ended. Sign in again.');
  }
  if (response.status === 419) {
    throw new ApiError(419, 'This page expired. Reload and try again; nothing was saved.');
  }
  const fallback: Record<number, string> = {
    403: "You don't have permission to do this. Ask an administrator if you need it.",
    404: 'That record no longer exists.',
    422: 'Some details need fixing.',
    429: 'Too many attempts. Wait a minute and try again.',
  };
  throw new ApiError(
    response.status,
    body.message || fallback[response.status] || 'Something went wrong. Nothing was changed.',
    body.errors ?? {},
  );
}

export async function getJson<T>(url: string, query?: Query, signal?: AbortSignal): Promise<T> {
  const response = await send(
    withQuery(url, query),
    {credentials: 'same-origin', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, signal},
    "Couldn't load this. Check your internet connection and try again.",
  );
  return handle<T>(response);
}

export async function postJson<T>(url: string, body: Record<string, unknown>): Promise<T> {
  const response = await send(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrfToken(),
    },
    body: JSON.stringify(body),
    // Every write route answers JSON when asked. A redirect means something
    // else answered (sign-in, two-factor, a read-only guard), so it is never
    // reported as saved.
    redirect: 'manual',
  }, "Couldn't connect. Check your internet and try again; nothing was changed.");
  if (response.type === 'opaqueredirect' || (response.status >= 300 && response.status < 400)) {
    throw new ApiError(0, 'The server did not confirm this change. Reload to check whether it was saved before trying again.');
  }
  return handle<T>(response);
}
