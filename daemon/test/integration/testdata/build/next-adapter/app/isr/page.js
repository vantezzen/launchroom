// Incremental static regeneration: rendered at build time, revalidated every 60 s.
export const revalidate = 60;

export default function IsrPage() {
  return (
    <main>
      <h1>ISR page</h1>
      <p>generated at {new Date().toISOString()}</p>
    </main>
  );
}
