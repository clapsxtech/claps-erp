// Routes for the overview area, relative to /app/u/:unit/ (or absolute under /app/ for
// global screens via `global`). Pages are lazy so each area loads on demand.
import {lazy} from 'react';
import type {AppRoute} from './types';

export const routes: AppRoute[] = [
  {path: 'home', component: lazy(() => import('../pages/Overview/Home'))},
  {path: 'tasks', component: lazy(() => import('../pages/Overview/Tasks'))},
  {path: 'insights', component: lazy(() => import('../pages/Overview/Insights'))},
  {path: 'exports', component: lazy(() => import('../pages/Overview/Exports'))},
  {path: 'sales-report', component: lazy(() => import('../pages/Overview/SalesReport'))},
];
