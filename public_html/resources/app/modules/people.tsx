// Routes for the people area, relative to /app/u/:unit/ (or absolute under /app/ for
// global screens via `global`). Pages are lazy so each area loads on demand.
import {lazy} from 'react';
import type {AppRoute} from './types';

export const routes: AppRoute[] = [
  {path: 'teachers', component: lazy(() => import('../pages/People/Teachers'))},
  {path: 'teachers/:teacher', component: lazy(() => import('../pages/People/TeacherDetail'))},
  {path: 'admissions', component: lazy(() => import('../pages/People/Admissions'))},
  {path: 'admissions/teachers', component: lazy(() => import('../pages/People/Directory'))},
  {path: 'admissions/:lead', component: lazy(() => import('../pages/People/LeadDetail'))},
  {path: 'followups', component: lazy(() => import('../pages/People/Followups'))},
  {path: 'recruitment', component: lazy(() => import('../pages/People/Recruitment'))},
  {path: 'recruitment/:candidate', component: lazy(() => import('../pages/People/CandidateDetail'))},
];
