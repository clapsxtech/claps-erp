// Sign-in, password reset, two-factor challenge and account security. Each page
// posts to its auth route (which answers JSON) and, after a sign-in,
// reloads the whole page because the session and CSRF token rotate.
import {lazy, Suspense, type ReactElement} from 'react';
import type {RouteObject} from 'react-router';
import {PageLoading} from '../components/PageState';

const Login = lazy(() => import('../pages/Guest/Login'));
const PasswordRequest = lazy(() => import('../pages/Guest/PasswordRequest'));
const PasswordReset = lazy(() => import('../pages/Guest/PasswordReset'));
const PasswordConfirm = lazy(() => import('../pages/Guest/PasswordConfirm'));
const TwoFactorChallenge = lazy(() => import('../pages/Guest/TwoFactorChallenge'));
const AccountSecurity = lazy(() => import('../pages/Guest/AccountSecurity'));

const page = (element: ReactElement) => <Suspense fallback={<PageLoading />}>{element}</Suspense>;

export const routes: RouteObject[] = [
  {path: '/login', element: page(<Login />)},
  {path: '/backend', element: page(<Login />)},
  {path: '/staff/login', element: page(<Login />)},
  {path: '/superadmin/login', element: page(<Login />)},
  {path: '/password/reset', element: page(<PasswordRequest />)},
  {path: '/password/reset/:token', element: page(<PasswordReset />)},
  {path: '/password/confirm', element: page(<PasswordConfirm />)},
  {path: '/two-factor', element: page(<TwoFactorChallenge />)},
  {path: '/account/security', element: page(<AccountSecurity />)},
];
