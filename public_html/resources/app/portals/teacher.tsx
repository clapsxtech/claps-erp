// The signed-in teacher portal at /teacher (sections at /teacher/<section>).
// Its own layout, no staff sidebar. The server embeds a teacher-only session
// (see App\Http\Controllers\App\TeacherPortalController).
import {lazy} from 'react';
import {Navigate, type RouteObject} from 'react-router';
import TeacherLayout from '../pages/Teacher/Layout';
import {NotFound} from '../pages/NotFound';

const Overview = lazy(() => import('../pages/Teacher/Overview'));
const Timetable = lazy(() => import('../pages/Teacher/Timetable'));
const Students = lazy(() => import('../pages/Teacher/Students'));
const Attendance = lazy(() => import('../pages/Teacher/Attendance'));
const Results = lazy(() => import('../pages/Teacher/Results'));
const Reports = lazy(() => import('../pages/Teacher/Reports'));

export const routes: RouteObject[] = [
  {
    path: '/teacher',
    element: <TeacherLayout />,
    children: [
      {index: true, element: <Navigate to="/teacher/overview" replace />},
      {path: 'overview', element: <Overview />},
      {path: 'timetable', element: <Timetable />},
      {path: 'students', element: <Students />},
      {path: 'attendance', element: <Attendance />},
      {path: 'results', element: <Results />},
      {path: 'reports', element: <Reports />},
      {path: '*', element: <NotFound />},
    ],
  },
];
