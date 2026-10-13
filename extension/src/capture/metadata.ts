/**
 * Page metadata, read from `JobPosting` JSON-LD and from nowhere else.
 *
 * A job board that publishes structured data is stating these facts; a board
 * that does not is not, and a heading that looks like a job title is a guess.
 * The contract says omitted rather than guessed, so a field with no structured
 * source is simply absent.
 */
export interface PostingMetadata {
  title?: string;
  company?: string;
  location?: string;
  salaryText?: string;
}

/** The backend caps each of these at 255 characters. */
const FIELD_LIMIT = 255;

export function jobPostingMetadata(doc: Document): PostingMetadata {
  for (const script of Array.from(doc.querySelectorAll('script[type="application/ld+json"]'))) {
    let data: unknown;

    try {
      data = JSON.parse(script.textContent ?? '');
    } catch {
      // A board with broken JSON-LD is a board with no metadata, not a failed
      // capture: the job description itself is what matters.
      continue;
    }

    const posting = findJobPosting(data, 0);

    if (posting !== null) {
      return metadataOf(posting);
    }
  }

  return {};
}

type Record_ = Record<string, unknown>;

const MAX_DEPTH = 6;

function findJobPosting(data: unknown, depth: number): Record_ | null {
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

function metadataOf(posting: Record_): PostingMetadata {
  const metadata: PostingMetadata = {};

  const title = text(posting['title']) ?? text(posting['name']);
  const company = organisationName(posting['hiringOrganization']);
  const location = placeName(posting['jobLocation']);
  const salaryText = salary(posting['baseSalary']);

  if (title !== null) {
    metadata.title = title;
  }

  if (company !== null) {
    metadata.company = company;
  }

  if (location !== null) {
    metadata.location = location;
  }

  if (salaryText !== null) {
    metadata.salaryText = salaryText;
  }

  return metadata;
}

function organisationName(value: unknown): string | null {
  if (typeof value === 'string') {
    return text(value);
  }

  return isRecord(value) ? text(value['name']) : null;
}

function placeName(value: unknown): string | null {
  const place = Array.isArray(value) ? value[0] : value;

  if (typeof place === 'string') {
    return text(place);
  }

  if (!isRecord(place)) {
    return null;
  }

  const address = place['address'];

  if (typeof address === 'string') {
    return text(address);
  }

  if (!isRecord(address)) {
    return text(place['name']);
  }

  const parts = ['addressLocality', 'addressRegion', 'addressCountry']
    .map((key) => countryOrText(address[key]))
    .filter((part): part is string => part !== null);

  return parts.length === 0 ? text(place['name']) : clamp(parts.join(', '));
}

function countryOrText(value: unknown): string | null {
  return isRecord(value) ? text(value['name']) : text(value);
}

/**
 * A board that states a salary as a sentence has already written the text. One
 * that states a `MonetaryAmount` has not, so the parts are joined as they
 * stand: inventing "per year" would put an English word in a data field, and
 * rounding or reformatting the numbers would change what the board said.
 */
function salary(value: unknown): string | null {
  if (typeof value === 'string') {
    return text(value);
  }

  if (!isRecord(value)) {
    return null;
  }

  const amount = value['value'];
  const parts = [text(value['currency'])];

  if (isRecord(amount)) {
    const min = text(amount['minValue']);
    const max = text(amount['maxValue']);
    const single = text(amount['value']);

    parts.push(min !== null && max !== null ? `${min}-${max}` : (single ?? min ?? max));
    parts.push(text(amount['unitText']));
  } else {
    parts.push(text(amount));
  }

  const joined = parts.filter((part): part is string => part !== null).join(' ');

  return joined === '' ? null : clamp(joined);
}

function hasType(data: Record_, type: string): boolean {
  const declared = data['@type'];

  if (typeof declared === 'string') {
    return declared === type;
  }

  return Array.isArray(declared) && declared.includes(type);
}

function isRecord(value: unknown): value is Record_ {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function text(value: unknown): string | null {
  if (typeof value === 'number') {
    return String(value);
  }

  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.replace(/\s+/g, ' ').trim();

  return trimmed === '' ? null : clamp(trimmed);
}

function clamp(value: string): string {
  return value.length > FIELD_LIMIT ? value.slice(0, FIELD_LIMIT) : value;
}
