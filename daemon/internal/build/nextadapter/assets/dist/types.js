// Minimal structural types for the parts of the Next.js Deployment Adapters
// API (next >= 16.2, `import type { NextAdapter } from 'next'`) this adapter
// uses. They are declared here so the package has no dependency on `next`
// (not even for types) and stays compatible across 16.x minors: Next passes
// the complete objects, we only read what is listed.
export {};
