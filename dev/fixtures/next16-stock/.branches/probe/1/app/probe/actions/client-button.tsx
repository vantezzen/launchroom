"use client";

import { useState } from "react";
import { echo } from "./actions";

// Calls the action from the browser: Next sends it as text/plain with a
// Next-Action header.
export function ClientButton() {
  const [result, setResult] = useState("");
  return (
    <button id="client" onClick={async () => setResult(JSON.stringify(await echo("from the client")))}>
      Call from the client {result}
    </button>
  );
}
