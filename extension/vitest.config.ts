import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environmentOptions: {
      // Extraction reads the page it is on, so a fixture needs a page URL for
      // the extractor to resolve links against and to report as the source.
      happyDOM: { url: 'https://jobs.example.com/vacancy/7781' },
    },
  },
});
