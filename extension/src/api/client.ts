import createClient from 'openapi-fetch';

import { log } from '../log';

import type { components, paths } from './schema';

/**
 * The backend's capture endpoint, as the OpenAPI document describes it.
 *
 * Every type here comes from `schema.ts`, which is generated from that
 * document, so the compiler is what keeps this client and the backend in step:
 * a field the backend adds or renames stops this file compiling.
 */
export type PostingCapture = components['schemas']['CapturePostingRequest'];

export type PostingStatus = components['schemas']['PostingStatus'];

export interface CapturedPosting {
  id: number;
  status: PostingStatus;
  /** False when the backend already held this posting. */
  isNew: boolean;
}

/**
 * Why a capture did not land. The caller picks the sentence; this says which.
 */
export type CaptureFailureReason =
  | 'unreachable'
  | 'token-rejected'
  | 'too-large'
  | 'rejected'
  | 'refused';

export class CaptureFailed extends Error {
  constructor(
    readonly reason: CaptureFailureReason,
    readonly status: number | null = null,
    readonly fields: readonly string[] = [],
  ) {
    super(reason);
    this.name = 'CaptureFailed';
  }
}

export interface ApiClient {
  capturePosting(capture: PostingCapture): Promise<CapturedPosting>;
}

/**
 * Nothing about a particular installation is built in: the base URL and the
 * token are arguments, and reading them from storage is the options page's job.
 */
export function createApiClient(baseUrl: string, token: string): ApiClient {
  const client = createClient<paths>({
    baseUrl,
    headers: { Authorization: `Bearer ${token}` },
  });

  return {
    async capturePosting(capture: PostingCapture): Promise<CapturedPosting> {
      log('capture send attempted', {
        sourceSite: capture.source_site,
        characters: capture.extracted_text.length,
        htmlCharacters: capture.raw_html.length,
      });

      let sent;

      try {
        sent = await client.POST('/api/v1/postings', { body: capture });
      } catch {
        // A thrown fetch is a backend asleep, unreachable or on a name that
        // does not resolve. Nothing arrived, so the capture is worth keeping.
        log('capture send failed', { reason: 'unreachable' });

        throw new CaptureFailed('unreachable');
      }

      const { data, error, response } = sent;

      if (data === undefined) {
        throw failureOf(response.status, error);
      }

      // The backend answers 201 for a posting this capture created and 200 for
      // one it already held. That is the whole difference between "Saved" and
      // "already saved", so it is read from the status rather than guessed.
      const posting: CapturedPosting = {
        id: data.id,
        status: data.status,
        isNew: response.status === 201,
      };

      log('capture send result', {
        status: response.status,
        postingId: posting.id,
        postingStatus: posting.status,
        isNew: posting.isNew,
      });

      return posting;
    },
  };
}

function failureOf(status: number, error: unknown): CaptureFailed {
  const reason: CaptureFailureReason =
    status === 401
      ? 'token-rejected'
      : status === 413
        ? 'too-large'
        : status === 422
          ? 'rejected'
          : 'refused';

  const fields = reason === 'rejected' ? failingFields(error) : [];

  log('capture send failed', { reason, status, fields });

  return new CaptureFailed(reason, status, fields);
}

/**
 * Field names only. The values are the posting's own text, and a log line does
 * not carry it.
 */
function failingFields(error: unknown): string[] {
  if (typeof error !== 'object' || error === null || !('errors' in error)) {
    return [];
  }

  const { errors } = error as { errors: unknown };

  return typeof errors === 'object' && errors !== null ? Object.keys(errors) : [];
}
