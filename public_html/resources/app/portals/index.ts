import type {RouteObject} from 'react-router';
import {routes as guest} from './guest';
import {routes as teacher} from './teacher';
import {routes as parent} from './parent';
import {routes as publicForms} from './public';
import {routes as print} from './print';

export const portalRoutes: RouteObject[] = [...guest, ...teacher, ...parent, ...publicForms, ...print];

/** URL prefixes the single-page app owns; links to them navigate without a reload. */
export const SPA_PREFIXES = ['/app', '/teacher', '/parent', '/login', '/password', '/two-factor', '/forms', '/print', '/account'];
