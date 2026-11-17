// Diagnostic only (probe branch): the URL the server sees.
export function loader({ request }: { request: Request }) {
  return Response.json({ url: request.url });
}
