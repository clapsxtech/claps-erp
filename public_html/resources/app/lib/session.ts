// Read once from the JSON the server-rendered host page embeds. The server already
// filtered `pages` by TeamPermissions, so the client never decides access;
// it only hides what the server would refuse anyway.

export interface UnitAccess {
  id: number;
  name: string;
  code: string;
  timezone: string;
  role: string;
  roleLabel: string;
  isArchived: boolean;
  pages: string[];
  /** Write actions this role may perform (empty for read-only and archived teams). */
  actions: string[];
  /** CSV datasets this role may download. */
  exports: string[];
}

export interface Session {
  user: {id: number; name: string; initials: string; isAdmin: boolean; isTeacher: boolean};
  units: UnitAccess[];
  sheetsAuthority: boolean;
  sheetsEnabled: boolean;
  legacyBase: string;
}

let cached: Session | null = null;

export function session(): Session {
  if (cached) return cached;
  const node = document.getElementById('erp-session');
  cached = JSON.parse(node?.textContent ?? '{}') as Session;
  return cached;
}

export function unitById(id: number): UnitAccess | undefined {
  return session().units.find(unit => unit.id === id);
}

const LAST_UNIT = 'erp:last-unit';

export function rememberUnit(id: number): void {
  try {
    localStorage.setItem(LAST_UNIT, String(id));
  } catch {
    // Private mode: the first unit is used instead.
  }
}

export function preferredUnit(): UnitAccess | undefined {
  const {units} = session();
  let stored: number | null = null;
  try {
    stored = Number(localStorage.getItem(LAST_UNIT));
  } catch {
    stored = null;
  }
  return units.find(unit => unit.id === stored) ?? units.find(unit => !unit.isArchived) ?? units[0];
}

/** For portals (teacher, parent, guest, public): read the host page's JSON as your own shape. */
export function readSession<T>(): T {
  const node = document.getElementById('erp-session');
  return JSON.parse(node?.textContent ?? '{}') as T;
}
