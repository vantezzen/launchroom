"use server";

// Diagnostic only (probe branch): echoes what it was sent.
export async function echo(input: string | FormData) {
  const value = typeof input === "string" ? input : String(input.get("value") ?? "");
  return { echoed: value, at: Date.now() };
}

export async function submit(formData: FormData) {
  await echo(formData);
}
