/**
 * The page's `JobPosting` JSON-LD object, found and not read.
 *
 * A board that publishes structured data is stating facts about the job, and
 * those facts are raw material like the page's text is. What they mean is the
 * backend's to decide: reading schema.org is a judgement about data, and a
 * judgement made in the browser is one nothing can revisit.
 *
 * So this finds the object and hands it over whole. Nothing here looks at a
 * single field of it.
 */
export type JobPostingJsonLd = Record<string, unknown>;

/**
 * How deep a page may nest the object before the search gives up. `@graph` and
 * a wrapping array account for two or three levels; anything past this is not a
 * page publishing its own vacancy.
 */
const MAX_DEPTH = 6;

export function jobPostingJsonLd(doc: Document): JobPostingJsonLd | null {
  for (const script of Array.from(doc.querySelectorAll('script[type="application/ld+json"]'))) {
    let data: unknown;

    try {
      data = JSON.parse(script.textContent ?? '');
    } catch {
      // A board with broken JSON-LD is a board that stated nothing, not a
      // failed capture: the page's text is what matters.
      continue;
    }

    const found = findJobPosting(data, 0);

    if (found !== null) {
      return found;
    }
  }

  return null;
}

function findJobPosting(data: unknown, depth: number): JobPostingJsonLd | null {
  if (depth > MAX_DEPTH) {
    return null;
  }

  if (Array.isArray(data)) {
    for (const entry of data) {
      const found = findJobPosting(entry, depth + 1);

      if (found !== null) {
        return found;
      }
    }

    return null;
  }

  if (!isRecord(data)) {
    return null;
  }

  if (hasType(data, 'JobPosting')) {
    return data;
  }

  // `@graph` is how a page publishes several entities at once.
  return findJobPosting(data['@graph'], depth + 1);
}

function hasType(data: JobPostingJsonLd, type: string): boolean {
  const declared = data['@type'];

  if (typeof declared === 'string') {
    return declared === type;
  }

  return Array.isArray(declared) && declared.includes(type);
}

function isRecord(value: unknown): value is JobPostingJsonLd {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
