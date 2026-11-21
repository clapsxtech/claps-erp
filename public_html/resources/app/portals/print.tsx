// Printable staff documents: /print/u/:unit/{receipt,invoice,request}/:id and
// /print/u/:unit/statement?student_id=&month=. Clean A4 pages with no app chrome;
// the Print and Back controls are hidden when printing.
import {lazy, Suspense, type ReactElement} from 'react';
import type {RouteObject} from 'react-router';
import {PrintFrame, PrintNotFound} from '../pages/Print/PrintFrame';
import {PageLoading} from '../components/PageState';

const Receipt = lazy(() => import('../pages/Print/Receipt'));
const Invoice = lazy(() => import('../pages/Print/Invoice'));
const PaymentRequest = lazy(() => import('../pages/Print/PaymentRequest'));
const Statement = lazy(() => import('../pages/Print/Statement'));

function page(element: ReactElement) {
  return <Suspense fallback={<PageLoading />}>{element}</Suspense>;
}

export const routes: RouteObject[] = [
  {
    path: '/print',
    element: <PrintFrame />,
    children: [
      {path: 'u/:unit/receipt/:id', element: page(<Receipt />)},
      {path: 'u/:unit/invoice/:id', element: page(<Invoice />)},
      {path: 'u/:unit/request/:id', element: page(<PaymentRequest />)},
      {path: 'u/:unit/statement', element: page(<Statement />)},
      {path: '*', element: <PrintNotFound />},
    ],
  },
];
