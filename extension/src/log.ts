const PREFIX = '[cv-tailor]';

/**
 * Builds a log line. Lines name the step they belong to and carry sizes and
 * outcomes — never the text of a job description.
 */
export function logLine(step: string, detail?: Record<string, unknown>): string {
  return detail === undefined ? `${PREFIX} ${step}` : `${PREFIX} ${step} ${JSON.stringify(detail)}`;
}

export function log(step: string, detail?: Record<string, unknown>): void {
  console.log(logLine(step, detail));
}
