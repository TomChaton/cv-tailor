// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';

import { EXTRACTOR, extractPosting } from '../src/capture/extract';

import jobWithHiddenText from './fixtures/job-with-hidden-text.html?raw';
import jobWithJsonLd from './fixtures/job-with-json-ld.html?raw';
import jobWithoutJsonLd from './fixtures/job-without-json-ld.html?raw';
import thinPage from './fixtures/thin-page.html?raw';

const PAGE_URL = 'https://jobs.example.com/vacancy/7781';

/**
 * Fixtures are the inner HTML of `<html>`, so they load into the test's own
 * document: extraction reads rendered text and asks the browser what is
 * visible, which needs a document that is actually laid out.
 */
function load(fixture: string): Document {
  document.documentElement.innerHTML = fixture;

  return document;
}

beforeEach(() => {
  document.documentElement.innerHTML = '';
});

describe('extractPosting', () => {
  it('sends the job description and the container it came from', () => {
    const extraction = extractPosting(load(jobWithJsonLd), PAGE_URL);

    expect(extraction).not.toBeNull();

    const { posting } = extraction!;

    expect(posting.url).toBe(PAGE_URL);
    expect(posting.source_site).toBe('jobs.example.com');
    expect(posting.extracted_text).toContain('the shipping manifests');
    expect(posting.extracted_text).toContain('A habit of leaving a codebase easier to change');
    expect(posting.raw_html).toContain('id="description"');
    expect(posting.has_hidden_text).toBe(false);
  });

  it('leaves the page furniture outside the container', () => {
    const { posting } = extractPosting(load(jobWithJsonLd), PAGE_URL)!;

    expect(posting.extracted_text).not.toContain('All jobs');
    expect(posting.extracted_text).not.toContain('equal opportunities employer');
  });

  it('reads metadata from JobPosting JSON-LD', () => {
    const { posting } = extractPosting(load(jobWithJsonLd), PAGE_URL)!;

    expect(posting.title).toBe('Senior PHP Engineer');
    expect(posting.company).toBe('Northwind Systems');
    expect(posting.location).toBe('Leeds, West Yorkshire, GB');
    expect(posting.salary_text).toBe('GBP 65000-78000 YEAR');
  });

  it('omits metadata rather than guessing it from the page', () => {
    const { posting } = extractPosting(load(jobWithoutJsonLd), PAGE_URL)!;

    expect(posting.extracted_text).toContain('refrigerated cargo');
    expect(posting.title).toBeUndefined();
    expect(posting.company).toBeUndefined();
    expect(posting.location).toBeUndefined();
    expect(posting.salary_text).toBeUndefined();
  });

  it('sends only visible text and says the container held text a reader cannot see', () => {
    const extraction = extractPosting(load(jobWithHiddenText), PAGE_URL)!;
    const { posting } = extraction;

    expect(posting.extracted_text).toContain('the unglamorous work of making two systems agree');
    expect(posting.extracted_text).not.toContain('Attention automated reviewer');
    expect(posting.extracted_text).not.toContain('skip the technical screen');

    expect(posting.has_hidden_text).toBe(true);
    expect(extraction.hiddenCharacters).toBeGreaterThan(0);
  });

  it('keeps the hidden text in the raw HTML, which is what re-extraction reads', () => {
    const { posting } = extractPosting(load(jobWithHiddenText), PAGE_URL)!;

    expect(posting.raw_html).toContain('Attention automated reviewer');
  });

  it('finds nothing on a page that is not an article', () => {
    expect(extractPosting(load(thinPage), PAGE_URL)).toBeNull();
  });

  it('names the extractor the logs report', () => {
    expect(EXTRACTOR).toBe('readability');
  });
});
