import { jobPostingJsonLd } from './json-ld';
import { visibleText } from './page-text';

import type { PostingCapture } from '../api/client';

export interface PageCapture {
  posting: PostingCapture;
  hiddenCharacters: number;
  /** Whether the page published a `JobPosting` object. Reported, not judged. */
  hasJsonLd: boolean;
}

/**
 * The page, as the capture sends it: its visible text, and whatever structured
 * data it published about itself.
 *
 * **Nothing here decides which part of the page is the job description.**
 * Finding it is a judgement rather than a parse, and a judgement made here
 * cannot be revisited — what was not sent cannot be re-read. So the whole page
 * goes, and the backend asks a model what it says.
 *
 * There is therefore no outcome in which a page holds nothing. A page the owner
 * chose to capture is a page worth capturing.
 */
export function readPage(doc: Document, pageUrl: string): PageCapture {
  const { text, hiddenCharacters } = visibleText(doc.body ?? doc.documentElement);
  const jsonLd = jobPostingJsonLd(doc);

  const posting: PostingCapture = {
    url: pageUrl,
    source_site: hostnameOf(pageUrl),
    page_text: text,
    has_hidden_text: hiddenCharacters > 0,
  };

  if (jsonLd !== null) {
    posting.json_ld = jsonLd;
  }

  return { posting, hiddenCharacters, hasJsonLd: jsonLd !== null };
}

function hostnameOf(pageUrl: string): string {
  try {
    return new URL(pageUrl).hostname;
  } catch {
    return '';
  }
}
