// Routes for the money area, relative to /app/u/:unit/ (or absolute under /app/ for
// global screens via `global`). Pages are lazy so each area loads on demand.
import {lazy} from 'react';
import type {AppRoute} from './types';

export const routes: AppRoute[] = [
  {path: 'payments', component: lazy(() => import('../pages/Money/Payments'))},
  {path: 'accounts', component: lazy(() => import('../pages/Money/Accounts'))},
  {path: 'tuition', component: lazy(() => import('../pages/Money/Tuition'))},
];
