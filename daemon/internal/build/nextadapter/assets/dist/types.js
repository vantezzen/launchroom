// Minimal structural types for the parts of the Next.js Deployment Adapters
// API (`import type { NextAdapter } from 'next'`) this adapter uses. They are
// declared here so the package has no dependency on `next` (not even for
// types) and stays compatible across versions: Next passes the complete
// objects, we only read what is listed. Next 15.4 and 15.5 call modifyConfig
// without a context and pass onBuildComplete only routes and a flat output
// list; 16.0 to 16.2 pass modifyConfig only the phase (and nextVersion from
// 16.2), 16.3 adds projectDir.
export {};
