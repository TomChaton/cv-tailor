/**
 * Whether a reader cannot see this element.
 *
 * `checkVisibility` covers what the browser itself hides — `display: none`,
 * `visibility: hidden`, `opacity: 0`. The rest covers text that is in the
 * layout but kept out of sight: the screen-reader-only patterns, a collapsed
 * font, content parked off the side of the page. Hidden text in a job listing
 * is sometimes aimed at whatever reads the page next, so extraction leaves it
 * out and the capture records that it was there.
 *
 * `aria-hidden` is deliberately not treated as hidden: it hides an element from
 * assistive technology, not from the person looking at the page.
 */
export function isHidden(element: Element): boolean {
  if (element.hasAttribute('hidden')) {
    return true;
  }

  if (!element.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) {
    return true;
  }

  const style = computedStyleOf(element);

  return style !== null && isKeptOutOfSight(style);
}

function computedStyleOf(element: Element): CSSStyleDeclaration | null {
  const view = element.ownerDocument.defaultView;

  return view === null ? null : view.getComputedStyle(element);
}

function isKeptOutOfSight(style: CSSStyleDeclaration): boolean {
  return isClipped(style) || isCollapsedText(style) || isOffScreen(style);
}

function isClipped(style: CSSStyleDeclaration): boolean {
  if (style.clip !== '' && style.clip !== 'auto') {
    return true;
  }

  // `inset(50%)` and `inset(100%)` clip an element away entirely; other inset
  // values are ordinary clipping of content that is still meant to be seen.
  return style.clipPath === 'inset(50%)' || style.clipPath === 'inset(100%)';
}

function isCollapsedText(style: CSSStyleDeclaration): boolean {
  const fontSize = Number.parseFloat(style.fontSize);

  return Number.isFinite(fontSize) && fontSize <= 0;
}

function isOffScreen(style: CSSStyleDeclaration): boolean {
  if (isFarNegative(style.textIndent)) {
    return true;
  }

  if (style.position !== 'absolute' && style.position !== 'fixed') {
    return false;
  }

  if (isFarNegative(style.left) || isFarNegative(style.top)) {
    return true;
  }

  return style.overflow === 'hidden' && isPinhole(style.width) && isPinhole(style.height);
}

function isFarNegative(value: string): boolean {
  const length = Number.parseFloat(value);

  return Number.isFinite(length) && length <= -1000;
}

function isPinhole(value: string): boolean {
  const length = Number.parseFloat(value);

  return Number.isFinite(length) && length <= 1;
}
