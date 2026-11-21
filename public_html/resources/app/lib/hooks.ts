import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {useSearchParams} from 'react-router';
import {getJson} from './api';

export interface Resource<T> {
  data: T | undefined;
  error: Error | undefined;
  isLoading: boolean;
  /** True while refreshing with the previous data still on screen. */
  isRefreshing: boolean;
  reload: () => void;
}

/**
 * Loads JSON and keeps the last good result visible while the next one loads,
 * so filtering and paging never flash an empty table.
 */
export function useResource<T>(url: string | null, query?: Record<string, string | number | boolean | undefined>): Resource<T> {
  const [data, setData] = useState<T>();
  const [error, setError] = useState<Error>();
  const [loading, setLoading] = useState(Boolean(url));
  const [nonce, setNonce] = useState(0);
  const key = url ? `${url}?${JSON.stringify(query ?? {})}` : null;

  useEffect(() => {
    if (!url) return;
    const controller = new AbortController();
    setLoading(true);
    getJson<T>(url, query, controller.signal)
      .then(result => {
        setData(result);
        setError(undefined);
      })
      .catch((reason: Error) => {
        if (reason.name !== 'AbortError') setError(reason);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
    // `key` captures url and query by value.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, nonce]);

  return {
    data,
    error,
    isLoading: loading && data === undefined,
    isRefreshing: loading && data !== undefined,
    reload: useCallback(() => setNonce(n => n + 1), []),
  };
}

/**
 * List state (search, filters, sort, page) lives in the URL, so the back button,
 * refresh and shared links all reopen exactly the same view.
 */
export function useListQuery<K extends string>(defaults: Record<K, string>) {
  const [params, setParams] = useSearchParams();
  const values = useMemo(() => {
    const out = {} as Record<K, string>;
    for (const key of Object.keys(defaults) as K[]) out[key] = params.get(key) ?? defaults[key];
    return out;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params]);

  const set = useCallback(
    (patch: Partial<Record<K | 'page', string>>, opts: {resetPage?: boolean} = {resetPage: true}) => {
      setParams(
        current => {
          const next = new URLSearchParams(current);
          for (const [key, value] of Object.entries(patch) as [string, string | undefined][]) {
            const fallback = (defaults as Record<string, string>)[key];
            if (value === undefined || value === '' || value === fallback) next.delete(key);
            else next.set(key, value);
          }
          if (opts.resetPage && !('page' in patch)) next.delete('page');
          return next;
        },
        {replace: true},
      );
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [setParams],
  );

  return [values, set] as const;
}

/** Debounces a value so typing in a search box sends one request, not one per key. */
export function useDebounced<T>(value: T, ms = 250): T {
  const [debounced, setDebounced] = useState(value);
  const first = useRef(true);
  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    const id = window.setTimeout(() => setDebounced(value), ms);
    return () => window.clearTimeout(id);
  }, [value, ms]);
  return debounced;
}

/** Matches the phone layout breakpoint used by the tab bar. */
export function useIsPhone(): boolean {
  const query = '(max-width: 767px)';
  const [phone, setPhone] = useState(() => window.matchMedia(query).matches);
  useEffect(() => {
    const media = window.matchMedia(query);
    const listener = () => setPhone(media.matches);
    media.addEventListener('change', listener);
    return () => media.removeEventListener('change', listener);
  }, []);
  return phone;
}
