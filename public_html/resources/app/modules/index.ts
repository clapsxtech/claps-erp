import {routes as overview} from './overview';
import {routes as people} from './people';
import {routes as classes} from './classes';
import {routes as money} from './money';
import {routes as admin} from './admin';
import type {AppRoute} from './types';

export const moduleRoutes: AppRoute[] = [...overview, ...people, ...classes, ...money, ...admin];
