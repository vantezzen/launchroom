import { defineConfig, globalIgnores } from 'eslint/config';
import nextVitals from 'eslint-config-next/core-web-vitals';

const eslintConfig = defineConfig([
    ...nextVitals,
    globalIgnores(['.next/**', 'build/**', 'next-env.d.ts', '.source/**']),
]);

export default eslintConfig;
