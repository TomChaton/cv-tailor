import { Readability } from '@mozilla/readability';

import { jobPostingMetadata } from './metadata';
import { isHidden } from './visibility';

import type { PostingCapture } from '../api/client';

/**
 * The generic article extractor handles every site. A per-site layer keyed on
 * hostname is a separate decision; this name is what the logs report so the two
 * are told apart when it exists.
 */
export const EXTRACTOR = 'readability';

/**
 * Marks the clone Readability is given so its answer can be mapped back to the
 * live page. Readability strips `class` and `style` but leaves data attributes
 * alone, and it is handed a clone because it rewrites the document it reads.
 */
const MARKER = 'data-cv-tailor-node';

/**
 * Readability hands back its best attempt even when it found nothing much, so
 * the floor is applied here rather than left to it. Any job description runs to
 * several times this; a sign-in page or a search result does not.
 */
const MINIMUM_CHARACTERS = 500;

export interface Extraction {
  posting: PostingCapture;
  /** How much text a reader could not see. Counted, never sent. */
  hiddenCharacters: number;
}

export function extractPosting(doc: Document, pageUrl: string): Extraction | null {
  const container = findContainer(doc);

  if (container === null) {
    return null;
  }

  const { text, hiddenCharacters } = visibleText(container);

  if (text.length < MINIMUM_CHARACTERS) {
    return null;
  }

  const metadata = jobPostingMetadata(doc);

  const posting: PostingCapture = {
    url: pageUrl,
    source_site: hostnameOf(pageUrl),
    extracted_text: text,
    raw_html: container.outerHTML,
    has_hidden_text: hiddenCharacters > 0,
  };

  if (metadata.title !== undefined) {
    posting.title = metadata.title;
  }

  if (metadata.company !== undefined) {
    posting.company = metadata.company;
  }

  if (metadata.location !== undefined) {
    posting.location = metadata.location;
  }

  if (metadata.salaryText !== undefined) {
    posting.salary_text = metadata.salaryText;
  }

  return { posting, hiddenCharacters };
}

/**
 * The live element the article lives in.
 *
 * Readability reads a clone whose hidden elements have been removed first, so
 * text a reader cannot see cannot steer which part of the page it picks. The
 * clone's elements carry markers, so the nodes it kept name live elements, and
 * the element that contains all of them is the container.
 */
function findContainer(doc: Document): Element | null {
  const clone = doc.cloneNode(true) as Document;
  const live = mark(doc, clone);

  prune(clone, live);

  const article = new Readability<Element>(clone, {
    serializer: (node) => node as Element,
  }).parse();

  if (article === null || article.content === null || article.content === undefined) {
    return null;
  }

  const kept = markersIn(article.content)
    .map((index) => live[index])
    .filter((element): element is Element => element !== undefined);

  if (kept.length === 0) {
    return null;
  }

  const container = commonAncestorOf(kept);

  // An article that reaches the root is an article that is the whole page.
  return container === doc.documentElement ? doc.body : container;
}

function mark(doc: Document, clone: Document): Element[] {
  const live: Element[] = [];

  const walk = (liveElement: Element, cloneElement: Element): void => {
    cloneElement.setAttribute(MARKER, String(live.length));
    live.push(liveElement);

    const liveChildren = liveElement.children;
    const cloneChildren = cloneElement.children;

    // The clone is an exact copy, so the two child lists line up; the minimum
    // is belt and braces rather than an expected case.
    for (let i = 0; i < Math.min(liveChildren.length, cloneChildren.length); i += 1) {
      walk(liveChildren[i]!, cloneChildren[i]!);
    }
  };

  if (doc.documentElement !== null && clone.documentElement !== null) {
    walk(doc.documentElement, clone.documentElement);
  }

  return live;
}

function prune(clone: Document, live: readonly Element[]): void {
  for (const element of Array.from(clone.querySelectorAll(`[${MARKER}]`))) {
    const liveElement = live[Number(element.getAttribute(MARKER))];

    if (liveElement !== undefined && isHidden(liveElement)) {
      element.remove();
    }
  }
}

function markersIn(content: Element): number[] {
  const indices: number[] = [];

  // Readability returns its own wrapper around the nodes it kept, so the
  // wrapper itself carries no marker and only what is inside it names the page.
  for (const element of [content, ...Array.from(content.querySelectorAll(`[${MARKER}]`))]) {
    const marker = element.getAttribute(MARKER);
    const index = marker === null ? Number.NaN : Number(marker);

    if (Number.isInteger(index)) {
      indices.push(index);
    }
  }

  return indices;
}

function commonAncestorOf(elements: readonly Element[]): Element {
  let ancestor = elements[0]!;

  for (const element of elements) {
    while (!ancestor.contains(element)) {
      const parent = ancestor.parentElement;

      if (parent === null) {
        return ancestor;
      }

      ancestor = parent;
    }
  }

  return ancestor;
}

interface VisibleText {
  text: string;
  hiddenCharacters: number;
}

/**
 * The rendered text of the container, with everything a reader cannot see left
 * out.
 *
 * `innerText` is the browser's own answer to "what does this say", so it is
 * what a subtree with nothing hidden in it is read with. A subtree that does
 * hold something hidden is walked instead, because `innerText` would include
 * text that is merely clipped or parked off-screen. `textContent` is never
 * used: it reports markup, not a page.
 */
export function visibleText(root: Element): VisibleText {
  const { hidden, holdingHidden, hiddenCharacters } = survey(root);

  const read = (element: Element): string => {
    if (hidden.has(element)) {
      return '';
    }

    if (!holdingHidden.has(element)) {
      return renderedText(element);
    }

    const parts: string[] = [];

    for (const node of Array.from(element.childNodes)) {
      if (node.nodeType === node.TEXT_NODE) {
        parts.push((node as Text).data);
      } else if (node.nodeType === node.ELEMENT_NODE) {
        parts.push(read(node as Element));
      }
    }

    return parts.join('\n');
  };

  return { text: normalise(read(root)), hiddenCharacters };
}

interface Survey {
  hidden: Set<Element>;
  holdingHidden: Set<Element>;
  hiddenCharacters: number;
}

function survey(root: Element): Survey {
  const hidden = new Set<Element>();
  const holdingHidden = new Set<Element>();
  let hiddenCharacters = 0;

  const visit = (element: Element): void => {
    if (isHidden(element)) {
      hidden.add(element);
      hiddenCharacters += squash(element.textContent ?? '').length;

      for (
        let ancestor = element.parentElement;
        ancestor !== null && root.contains(ancestor);
        ancestor = ancestor.parentElement
      ) {
        holdingHidden.add(ancestor);
      }

      // Everything inside a hidden element is hidden too.
      return;
    }

    for (const child of Array.from(element.children)) {
      visit(child);
    }
  };

  visit(root);

  return { hidden, holdingHidden, hiddenCharacters };
}

function renderedText(element: Element): string {
  return element instanceof HTMLElement ? element.innerText : squash(element.textContent ?? '');
}

function normalise(text: string): string {
  return text
    .replace(/\r\n?/g, '\n')
    .replace(/[^\S\n]+/g, ' ')
    .replace(/ ?\n ?/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

function squash(text: string): string {
  return text.replace(/\s+/g, ' ').trim();
}

function hostnameOf(pageUrl: string): string {
  try {
    return new URL(pageUrl).hostname;
  } catch {
    return '';
  }
}
