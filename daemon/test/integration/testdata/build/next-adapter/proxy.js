// Next 16 "proxy" (formerly middleware): tags every page response.
import { NextResponse } from 'next/server';

export function proxy() {
  const res = NextResponse.next();
  res.headers.set('x-launchroom-proxy', '1');
  return res;
}

export const config = { matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'] };
