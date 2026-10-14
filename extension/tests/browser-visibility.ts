/**
 * Teaches the test DOM the one visibility answer a browser gives and it does
 * not.
 *
 * What a reader can see is decided with `checkVisibility`, which a browser
 * answers about boxes: an element that generates none is reported as not
 * visible. This DOM lays nothing out, so it reports a box-less element as
 * visible instead — and a page a browser reads as blank would read perfectly
 * here. A fixture carrying that page would then prove nothing, which is the
 * worst kind of test. Where the suite leans on the DOM's visibility answer, the
 * answer has to be the browser's.
 */
if (typeof Element !== 'undefined') {
  const answer = Element.prototype.checkVisibility;

  Element.prototype.checkVisibility = function checkVisibility(
    this: Element,
    options?: CheckVisibilityOptions,
  ): boolean {
    const style = this.ownerDocument.defaultView?.getComputedStyle(this);

    if (style !== undefined && style.display === 'contents') {
      return false;
    }

    return answer.call(this, options);
  };
}
