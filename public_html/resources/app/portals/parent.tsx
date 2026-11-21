// Family portal at /parent. The server signs families in (student ID + date of
// birth) and embeds only that child's header in the host page; each section
// loads its own JSON from /parent-api, scoped to the signed-in student.
import {lazy, Suspense, type ReactElement} from 'react';
import type {RouteObject} from 'react-router';
import {PageLoading} from '../components/PageState';
import {NotFound} from '../pages/NotFound';

const Layout = lazy(() => import('../pages/Parent/Layout'));
const Login = lazy(() => import('../pages/Parent/Login'));
const Overview = lazy(() => import('../pages/Parent/Overview'));
const Classes = lazy(() => import('../pages/Parent/Classes'));
const Results = lazy(() => import('../pages/Parent/Results'));
const Fees = lazy(() => import('../pages/Parent/Fees'));
const Document = lazy(() => import('../pages/Parent/Documents').then(m => ({default: m.ParentDocument})));
const Statement = lazy(() => import('../pages/Parent/Documents').then(m => ({default: m.ParentStatement})));

function page(element: ReactElement) {
  return <Suspense fallback={<PageLoading />}>{element}</Suspense>;
}

export const routes: RouteObject[] = [
  {path: '/parent/login', element: page(<Login />)},
  {path: '/parent/statement', element: page(<Statement />)},
  {path: '/parent/documents/:type/:id', element: page(<Document />)},
  {
    path: '/parent',
    element: page(<Layout />),
    children: [
      {index: true, element: page(<Overview />)},
      {path: 'overview', element: page(<Overview />)},
      {path: 'classes', element: page(<Classes />)},
      {path: 'results', element: page(<Results />)},
      {path: 'fees', element: page(<Fees />)},
      {path: '*', element: <NotFound />},
    ],
  },
];
