export const dynamic = 'force-dynamic';

export default function Page() {
  return <p>Hello from launchroom (Next.js standalone, api: {process.env.NEXT_PUBLIC_API_URL ?? 'unset'})</p>;
}
