import js from '@eslint/js';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  { ignores: ['dist/**', 'node_modules/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    // The build script runs under Node rather than in the browser.
    files: ['scripts/**/*.mjs'],
    languageOptions: {
      globals: { console: 'readonly' },
    },
  },
);
