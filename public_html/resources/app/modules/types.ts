import type {LazyExoticComponent, ComponentType} from 'react';

export interface AppRoute {
  /** Path under /app/u/:unit/, e.g. "teachers" or "teachers/:teacher". */
  path: string;
  component: LazyExoticComponent<ComponentType>;
  /** When true, `path` is under /app/ instead of a team, e.g. "account/security". */
  global?: boolean;
}
