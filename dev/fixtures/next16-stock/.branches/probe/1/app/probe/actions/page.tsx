import { submit } from "./actions";
import { ClientButton } from "./client-button";

// Diagnostic page (probe branch): a form action (its id is in the HTML as
// the $ACTION_ID_ field) and a client-called action.
export default function Page() {
  return (
    <main>
      <form action={submit}>
        <input name="value" defaultValue="from the form" />
        <button type="submit">Submit</button>
      </form>
      <ClientButton />
    </main>
  );
}
