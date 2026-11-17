import { cacheLife } from "next/cache";

// Diagnostic page (probe branch): output cached and revalidated every 60 s.
async function Stamp() {
  "use cache";
  cacheLife({ stale: 30, revalidate: 60, expire: 3600 });
  return <p id="stamp">{new Date().toISOString()}</p>;
}

export default function Page() {
  return <Stamp />;
}
