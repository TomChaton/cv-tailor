// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';

import { readPage } from '../src/capture/read-page';

import jobWithHiddenText from './fixtures/job-with-hidden-text.html?raw';
import jobWithJsonLd from './fixtures/job-with-json-ld.html?raw';
import jobWithoutJsonLd from './fixtures/job-without-json-ld.html?raw';
import thinPage from './fixtures/thin-page.html?raw';

const PAGE_URL = 'https://jobs.example.com/vacancy/7781';

/**
 * Fixtures are the inner HTML of `<html>`, so they load into the test's own
 * document: reading a page reads rendered text and asks the browser what is
 * visible, which needs a document that is actually laid out.
 */
function load(fixture: string): Document {
  document.documentElement.innerHTML = fixture;

  return document;
}

beforeEach(() => {
  document.documentElement.innerHTML = '';
});

describe('readPage', () => {
  it('sends the whole page and where it was read', () => {
    const { posting } = readPage(load(jobWithJsonLd), PAGE_URL);

    expect(posting.url).toBe(PAGE_URL);
    expect(posting.source_site).toBe('jobs.example.com');
    expect(posting.page_text).toContain('the shipping manifests');
    expect(posting.page_text).toContain('A habit of leaving a codebase easier to change');
    expect(posting.has_hidden_text).toBe(false);
  });

  /**
   * Nothing here chooses a container, so the furniture around the posting
   * arrives with it. Which part of the page is the job description is the
   * backend's judgement.
   */
  it('keeps the page furniture rather than deciding what the posting is', () => {
    const { posting } = readPage(load(jobWithJsonLd), PAGE_URL);

    expect(posting.page_text).toContain('All jobs');
    expect(posting.page_text).toContain('equal opportunities employer');
  });

  it('sends the JobPosting object as the page published it, uninterpreted', () => {
    const { posting, hasJsonLd } = readPage(load(jobWithJsonLd), PAGE_URL);

    expect(hasJsonLd).toBe(true);
    expect(posting.json_ld).toEqual({
      '@context': 'https://schema.org',
      '@type': 'JobPosting',
      title: 'Senior PHP Engineer',
      hiringOrganization: { '@type': 'Organization', name: 'Northwind Systems' },
      jobLocation: {
        '@type': 'Place',
        address: {
          '@type': 'PostalAddress',
          addressLocality: 'Leeds',
          addressRegion: 'West Yorkshire',
          addressCountry: 'GB',
        },
      },
      baseSalary: {
        '@type': 'MonetaryAmount',
        currency: 'GBP',
        value: {
          '@type': 'QuantitativeValue',
          minValue: 65000,
          maxValue: 78000,
          unitText: 'YEAR',
        },
      },
    });
  });

  it('sends no structured data for a page that published none', () => {
    const { posting, hasJsonLd } = readPage(load(jobWithoutJsonLd), PAGE_URL);

    expect(posting.page_text).toContain('refrigerated cargo');
    expect(hasJsonLd).toBe(false);
    expect(posting.json_ld).toBeUndefined();
  });

  it('finds a JobPosting a page publishes inside a graph', () => {
    const { posting } = readPage(
      load(`<head><script type="application/ld+json">
        {"@context":"https://schema.org","@graph":[
          {"@type":"WebSite","name":"A job board"},
          {"@type":"JobPosting","title":"Platform Engineer"}
        ]}
      </script></head><body><p>A role.</p></body>`),
      PAGE_URL,
    );

    expect(posting.json_ld).toEqual({ '@type': 'JobPosting', title: 'Platform Engineer' });
  });

  it('treats a page with broken structured data as one that published none', () => {
    const { posting } = readPage(
      load(
        '<head><script type="application/ld+json">{ not json </script></head>' +
          '<body><p>A role worth keeping.</p></body>',
      ),
      PAGE_URL,
    );

    expect(posting.json_ld).toBeUndefined();
    expect(posting.page_text).toContain('A role worth keeping.');
  });

  it('sends only visible text and says the page held text a reader cannot see', () => {
    const read = readPage(load(jobWithHiddenText), PAGE_URL);
    const { posting } = read;

    expect(posting.page_text).toContain('the unglamorous work of making two systems agree');
    expect(posting.page_text).not.toContain('Attention automated reviewer');
    expect(posting.page_text).not.toContain('skip the technical screen');

    expect(posting.has_hidden_text).toBe(true);
    expect(read.hiddenCharacters).toBeGreaterThan(0);
  });

  /**
   * `aria-hidden` hides an element from assistive technology, not from the
   * person looking at the page.
   */
  it('does not treat aria-hidden as hidden', () => {
    const { posting } = readPage(
      load('<body><p aria-hidden="true">A decorative aside the reader can see.</p></body>'),
      PAGE_URL,
    );

    expect(posting.page_text).toContain('A decorative aside the reader can see.');
    expect(posting.has_hidden_text).toBe(false);
  });

  /**
   * There is no floor and no "nothing found". A page the owner chose to capture
   * is a page worth capturing, and whether it advertises a job is the backend's
   * answer.
   */
  it('reads a page that is not an article rather than refusing it', () => {
    const { posting } = readPage(load(thinPage), PAGE_URL);

    expect(posting.page_text).toContain('Sign in');
    expect(posting.has_hidden_text).toBe(false);
  });

  it('reads a page whose text is one line', () => {
    const { posting } = readPage(load('<body><p>Senior PHP Engineer, Leeds.</p></body>'), PAGE_URL);

    expect(posting.page_text).toBe('Senior PHP Engineer, Leeds.');
  });
});
