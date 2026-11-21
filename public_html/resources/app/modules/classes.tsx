// Routes for the classes area, relative to /app/u/:unit/ (or absolute under /app/ for
// global screens via `global`). Pages are lazy so each area loads on demand.
import {lazy} from 'react';
import type {AppRoute} from './types';

export const routes: AppRoute[] = [
  {path: 'timetable', component: lazy(() => import('../pages/Classes/Timetable'))},
  {path: 'attendance', component: lazy(() => import('../pages/Classes/Attendance'))},
  {path: 'matching', component: lazy(() => import('../pages/Classes/Matching'))},
  {path: 'academics', component: lazy(() => import('../pages/Classes/Academics'))},
];
