import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // Reading a page asks the DOM what a reader can see, and this one answers
    // more generously than a browser does. See the file.
    setupFiles: ['./tests/browser-visibility.ts'],
    environmentOptions: {
      // Extraction reads the page it is on, so a fixture needs a page URL for
      // the extractor to resolve links against and to report as the source.
      happyDOM: { url: 'https://jobs.example.com/vacancy/7781' },
    },
  },
});
