import { NextResponse } from "next/server";

// Diagnostic (probe branch): marks every response that went through the proxy.
export function proxy() {
  const res = NextResponse.next();
  res.headers.set("x-probe-proxy", "1");
  return res;
}
