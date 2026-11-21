import {readSession} from '../../lib/session';

export interface GuestSession {
  kind: 'guest';
  screen: string;
  /** One-time flash message from the server (for example "Your password has been reset."). */
  status: string | null;
  portal?: 'staff' | 'superadmin' | null;
  intended?: string;
}

export function guestSession(): GuestSession {
  return readSession<GuestSession>();
}
