// Routes for the admin area, relative to /app/u/:unit/ (or absolute under /app/ for
// global screens via `global`). Pages are lazy so each area loads on demand.
import {lazy} from 'react';
import type {AppRoute} from './types';

export const routes: AppRoute[] = [
  {path: 'team', component: lazy(() => import('../pages/Admin/TeamAccess'))},
  {path: 'admin/teams', global: true, component: lazy(() => import('../pages/Admin/Teams'))},
  {path: 'admin/operations', global: true, component: lazy(() => import('../pages/Admin/Operations'))},
  {path: 'admin/migration', global: true, component: lazy(() => import('../pages/Admin/Migration'))},
  {path: 'account/security', global: true, component: lazy(() => import('../pages/Admin/Security'))},
];
