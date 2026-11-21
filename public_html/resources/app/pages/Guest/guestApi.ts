// Posts a form to an auth route as JSON. Unlike lib/api postJson, it
// returns the whole body (redirect targets, one-time codes) and picks up a
// rotated CSRF token when the server hands one back.
export interface GuestResult<T> {
  ok: boolean;
  status: number;
  body: T & {message?: string; errors?: Record<string, string[]>; csrfToken?: string; redirect?: string};
}

function tokenMeta(): HTMLMetaElement | null {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
}

export async function guestPost<T = Record<string, unknown>>(url: string, data: Record<string, unknown>): Promise<GuestResult<T>> {
  let response: Response;
  try {
    response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': tokenMeta()?.content ?? '',
      },
      body: JSON.stringify(data),
      redirect: 'manual',
    });
  } catch {
    return {ok: false, status: 0, body: {message: 'Could not reach the server. Check your connection and try again.'} as GuestResult<T>['body']};
  }
  let body = {} as GuestResult<T>['body'];
  try {
    body = await response.json();
  } catch {
    // Redirects and HTML error pages carry no JSON.
  }
  if (body.csrfToken) tokenMeta()?.setAttribute('content', body.csrfToken);
  if (response.type === 'opaqueredirect') {
    // Something other than the JSON branch answered: on sign-in pages the guest
    // middleware (already signed in), elsewhere an expired session.
    return {ok: false, status: 302, body: {...body, message: 'Your session changed. Reload the page and try again.', redirect: '/'}};
  }
  if (!response.ok && !body.message) {
    const fallback: Record<number, string> = {
      403: 'This step is no longer available. Start again.',
      419: 'This page expired. Reload it and try again.',
      429: 'Too many attempts. Wait a minute and try again.',
    };
    body.message = fallback[response.status] ?? 'Something went wrong. Try again.';
  }
  return {ok: response.ok, status: response.status, body};
}

/** First error for a field, for TextInput `status`. */
export function fieldStatus(errors: Record<string, string[]> | undefined, name: string) {
  const message = errors?.[name]?.[0];
  return message ? {type: 'error' as const, message} : undefined;
}

/** Errors that are not shown beside a field go in the banner. */
export function bannerMessage(result: GuestResult<unknown>, fields: string[]): string | undefined {
  const errors = result.body.errors ?? {};
  const unplaced = Object.entries(errors).filter(([key]) => !fields.includes(key)).flatMap(([, messages]) => messages);
  if (unplaced.length) return unplaced.join(' ');
  if (Object.keys(errors).some(key => fields.includes(key))) return undefined;
  return result.body.message;
}
