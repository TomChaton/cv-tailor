import { isHidden } from './visibility';

export interface PageText {
  text: string;
  /** How much text a reader could not see. Counted, never sent. */
  hiddenCharacters: number;
}

/**
 * The rendered text of a page, with everything a reader cannot see left out.
 *
 * `innerText` is the browser's own answer to "what does this say", so it is
 * what a subtree with nothing hidden in it is read with. A subtree that does
 * hold something hidden is walked instead, because `innerText` would include
 * text that is merely clipped or parked off-screen — which is exactly what a
 * listing aimed at an automated reader uses. `textContent` is never used: it
 * reports markup, not a page.
 *
 * Content the page never renders is neither read nor counted as hidden: see
 * `isNeverRendered`.
 */
export function visibleText(root: Element): PageText {
  const { hidden, holdingHidden, hiddenCharacters } = survey(root);

  const read = (element: Element): string => {
    if (hidden.has(element) || isNeverRendered(element)) {
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
    if (isNeverRendered(element)) {
      return;
    }

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

/**
 * Elements whose contents a page never renders.
 *
 * Their source is not text a reader was kept from seeing; it is not text the
 * page says at all. So it is neither read nor counted as hidden, because the
 * hidden-text warning is only worth drawing if it is rare: a page's own markup
 * would set it on nearly every capture, and the owner would learn to ignore
 * the one signal that says a listing is talking to the model.
 *
 * The question is settled on the element and never on its computed display: a
 * page's own source is markup however the page styles it. So a listing cannot
 * have its script counted as text somebody was kept from seeing by giving it a
 * box, and the decision does not rest on the `display: none` the user-agent
 * stylesheet happens to put on these elements.
 */
const NEVER_RENDERED = new Set(['script', 'style', 'noscript', 'template']);

function isNeverRendered(element: Element): boolean {
  return NEVER_RENDERED.has(element.localName);
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
