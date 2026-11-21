// Family portal plumbing: the session the host page embeds, a JSON client whose
// "signed out" answer goes to the parent sign-in (not the staff one), and time
// formatting in the student's time zone (lesson times arrive as UTC ISO strings).
import {useCallback, useEffect, useState} from 'react';
import {ApiError, withQuery} from '../../lib/api';
import {readSession} from '../../lib/session';

export interface ParentSession {
  kind: 'parent';
  signedIn: boolean;
  status: string | null;
  student?: {name: string; code: string; grade: string; syllabus: string; timezone: string};
  centre?: {name: string; phone: string | null};
}

let cached: ParentSession | null = null;
export function parentSession(): ParentSession {
  if (!cached) cached = readSession<ParentSession>();
  return cached;
}

export function timezone(): string {
  return parentSession().student?.timezone ?? 'Asia/Kolkata';
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function signedOut(): never {
  window.location.assign('/parent/login');
  throw new ApiError(401, 'Your session has ended. Sign in again.');
}

async function handle<T>(response: Response): Promise<T> {
  if (response.ok) return (await response.json()) as T;
  let body: {message?: string; errors?: Record<string, string[]>} = {};
  try {
    body = await response.json();
  } catch {
    // Not JSON: use the status message below.
  }
  if (response.status === 401) signedOut();
  if (response.status === 419) throw new ApiError(419, 'This page expired. Reload and try again; nothing was sent.');
  const fallback: Record<number, string> = {404: 'This document could not be found.', 422: 'Please check the details.', 429: 'Too many attempts. Please wait and try again.'};
  throw new ApiError(response.status, body.message || fallback[response.status] || 'Something went wrong. Please try again.', body.errors ?? {});
}

export async function parentGet<T>(url: string, query?: Record<string, string | number | undefined>, signal?: AbortSignal): Promise<T> {
  const response = await fetch(withQuery(url, query), {credentials: 'same-origin', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, signal});
  return handle<T>(response);
}

/** Posts to the portal routes, which answer JSON when asked. A redirect is never taken as success. */
export async function parentPost<T>(url: string, body: Record<string, unknown>, opts: {signedOutRedirect?: boolean} = {signedOutRedirect: true}): Promise<T> {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken()},
    body: JSON.stringify(body),
    redirect: 'manual',
  });
  if (response.type === 'opaqueredirect' || (response.status >= 300 && response.status < 400)) {
    throw new ApiError(0, 'The centre did not confirm this. Reload the page to check before trying again.');
  }
  if (response.status === 401 && !opts.signedOutRedirect) throw new ApiError(401, 'Your session has ended. Sign in again.');
  return handle<T>(response);
}

export function useParentData<T>(url: string, query?: Record<string, string | number | undefined>) {
  const [data, setData] = useState<T>();
  const [error, setError] = useState<Error>();
  const [nonce, setNonce] = useState(0);
  const key = `${url}?${JSON.stringify(query ?? {})}`;
  useEffect(() => {
    const controller = new AbortController();
    parentGet<T>(url, query, controller.signal)
      .then(result => {
        setData(result);
        setError(undefined);
      })
      .catch((reason: Error) => {
        if (reason.name !== 'AbortError') setError(reason);
      });
    return () => controller.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, nonce]);
  return {data, error, reload: useCallback(() => setNonce(n => n + 1), [])};
}

// ---- Formatting -------------------------------------------------------------

const inr = new Intl.NumberFormat('en-IN', {style: 'currency', currency: 'INR', minimumFractionDigits: 2});
export function rupees(paise: number): string {
  return inr.format(paise / 100);
}

/** A balance in words: positive is paid in advance, negative is due. */
export function balanceWord(paise: number): string {
  return paise > 0 ? 'Paid in advance' : paise < 0 ? 'Due now' : 'Nothing due';
}

function inZone(iso: string, options: Intl.DateTimeFormatOptions): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString('en-IN', {timeZone: timezone(), ...options});
}

/** "Mon 3 Nov 2026" for a lesson start, in the student's zone. */
export function lessonDay(iso: string): string {
  return inZone(iso, {weekday: 'short', day: 'numeric', month: 'short', year: 'numeric'});
}

export function lessonTime(iso: string): string {
  return inZone(iso, {hour: '2-digit', minute: '2-digit', hour12: false});
}

export function lessonWhen(iso: string): string {
  return `${lessonDay(iso)}, ${lessonTime(iso)}`;
}

/** A plain calendar date (YYYY-MM-DD), shown without any zone shift. */
export function plainDate(value: string | null | undefined): string {
  if (!value) return '—';
  const [y, m, d] = value.slice(0, 10).split('-').map(Number);
  if (!y || !m || !d) return value;
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-IN', {timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric'});
}

export function kindSuffix(kind: string): string {
  return kind === 'demo' ? ' (demo)' : kind === 'extra' ? ' (extra class)' : '';
}

export interface Lesson {
  id: number;
  startsAt: string;
  endsAt: string;
  subject: string;
  teacher: string;
  kind: 'regular' | 'demo' | 'extra';
}

export interface Result {
  id: number;
  assessedOn: string;
  subject: string;
  assessment: string;
  score: number;
  maximum: number;
  note: string | null;
}
