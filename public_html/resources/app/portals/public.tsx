// Public, no-login teacher forms. Each URL is the classic one (bookmarks and
// messages to teachers keep working) and is served by
// App\Http\Controllers\App\PublicFormsController, which embeds only teacher
// names and subject/topic lists. The teacher verifies with their date of birth
// on every POST; see pages/Public/shared.tsx.
import {lazy, Suspense, type ReactElement} from 'react';
import type {RouteObject} from 'react-router';
import {PageLoading} from '../components/PageState';

const Attendance = lazy(() => import('../pages/Public/Attendance'));
const Result = lazy(() => import('../pages/Public/Result'));
const Performance = lazy(() => import('../pages/Public/Performance'));
const Timetable = lazy(() => import('../pages/Public/Timetable'));

function page(element: ReactElement) {
  return <Suspense fallback={<PageLoading />}>{element}</Suspense>;
}

export const routes: RouteObject[] = [
  {path: '/attendance', element: page(<Attendance />)},
  {path: '/resultform', element: page(<Result />)},
  {path: '/performance', element: page(<Performance />)},
  {path: '/timetable', element: page(<Timetable />)},
  {path: '/timetableReport', element: page(<Timetable />)},
];
