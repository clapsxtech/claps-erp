import {forwardRef, lazy, Suspense, type AnchorHTMLAttributes, type ReactElement} from 'react';
import {createBrowserRouter, Link as RouterLink, Navigate, RouterProvider, useParams} from 'react-router';
import {Theme} from '@astryxdesign/core/theme';
import {LinkProvider} from '@astryxdesign/core/Link';
import {clapsTheme} from '../../src/themes/claps/claps.js';
import {AppFrame} from './shell/AppFrame';
import {PageLoading} from './components/PageState';
import {NotFound, RouteError} from './pages/NotFound';
import {preferredUnit} from './lib/session';
import {moduleRoutes} from './modules';

// Astryx renders every link through this, so in-app links route without a reload
// while external and download links (marked with reloadDocument) still navigate.
const AppLink = forwardRef<HTMLAnchorElement, AnchorHTMLAttributes<HTMLAnchorElement> & {href?: string}>(
  function AppLink({href = '', ...rest}, ref) {
    const internal = href.startsWith('/app') && !rest.download && rest.target !== '_blank';
    if (!internal) return <a ref={ref} href={href} {...rest} />;
    return <RouterLink ref={ref} to={href} {...rest} />;
  },
);

const Students = lazy(() => import('./pages/Students'));
const StudentDetail = lazy(() => import('./pages/StudentDetail'));
const Placeholder = lazy(() => import('./pages/Placeholder'));

function page(element: ReactElement) {
  return <Suspense fallback={<PageLoading />}>{element}</Suspense>;
}

function DefaultUnit() {
  const unit = preferredUnit();
  return unit ? <Navigate to={`/app/u/${unit.id}/home`} replace /> : <NotFound title="No team assigned" description="Ask an administrator to add you to a team." />;
}

function UnitIndex() {
  const {unit} = useParams();
  return <Navigate to={`/app/u/${unit}/home`} replace />;
}

const router = createBrowserRouter([
  {
    path: '/app',
    element: <AppFrame />,
    errorElement: <RouteError />,
    children: [
      {index: true, element: <DefaultUnit />},
      {path: 'u/:unit', element: <UnitIndex />},
      {path: 'u/:unit/students', element: page(<Students />)},
      {path: 'u/:unit/students/:student', element: page(<StudentDetail />)},
      ...moduleRoutes.map(route => {
        const Component = route.component;
        return {path: route.global ? route.path : `u/:unit/${route.path}`, element: page(<Component />)};
      }),
      {path: 'u/:unit/:section', element: page(<Placeholder />)},
      {path: '*', element: <NotFound />},
    ],
  },
]);

export function App() {
  return (
    <Theme theme={clapsTheme}>
      <LinkProvider component={AppLink}>
        <RouterProvider router={router} />
      </LinkProvider>
    </Theme>
  );
}
