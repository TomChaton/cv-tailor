import { afterEach, describe, expect, it, vi } from 'vitest';

import { CaptureFailed, createApiClient } from '../src/api/client';

import type { paths } from '../src/api/schema';

/**
 * The path and the body are taken from the generated document rather than
 * written out here, so the round trip below is a round trip against the
 * document: if the backend stops describing this operation, or describes it
 * with a different body, `npm run lint` stops passing.
 */
const path: keyof paths = '/api/v1/postings';

type DocumentedCapture =
  paths['/api/v1/postings']['post']['requestBody']['content']['application/json'];

const capture: DocumentedCapture = {
  url: 'https://jobs.example.com/vacancy/7781',
  source_site: 'jobs.example.com',
  page_text: 'Senior PHP Engineer. We are looking for a PHP engineer to join the platform team.',
  json_ld: { '@type': 'JobPosting', title: 'Senior PHP Engineer' },
  has_hidden_text: false,
};

function answer(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function stubFetch(response: Response | Error): ReturnType<typeof vi.fn> {
  const fetch = vi.fn(() =>
    response instanceof Error ? Promise.reject(response) : Promise.resolve(response),
  );

  vi.stubGlobal('fetch', fetch);

  return fetch;
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('capturePosting', () => {
  it('sends the capture to the documented endpoint with the bearer token', async () => {
    const fetch = stubFetch(answer(201, { id: 12, status: 'captured' }));

    await createApiClient('https://cv-tailor.example.net', 'token-abc').capturePosting(capture);

    expect(fetch).toHaveBeenCalledOnce();

    const request = fetch.mock.calls[0]![0] as Request;

    expect(request.method).toBe('POST');
    expect(new URL(request.url).pathname).toBe(path);
    expect(request.headers.get('Authorization')).toBe('Bearer token-abc');
    expect(await request.json()).toEqual(capture);
  });

  /**
   * JSON is the one content type this client speaks. The backend answers a
   * refusal as JSON whatever a caller says it accepts, so this is politeness
   * rather than a dependency — but a client that states nothing is a client
   * relying on a server's default.
   */
  it('says it accepts json', async () => {
    const fetch = stubFetch(answer(201, { id: 12, status: 'captured' }));

    await createApiClient('https://cv-tailor.example.net', 'token-abc').capturePosting(capture);

    const request = fetch.mock.calls[0]![0] as Request;

    expect(request.headers.get('Accept')).toBe('application/json');
  });

  it('reads a new posting off 201', async () => {
    stubFetch(answer(201, { id: 12, status: 'captured' }));

    const posting = await createApiClient('https://cv-tailor.example.net', 't').capturePosting(
      capture,
    );

    expect(posting).toEqual({ id: 12, status: 'captured', isNew: true });
  });

  it('reads a posting the backend already held off 200', async () => {
    stubFetch(answer(200, { id: 12, status: 'reviewed' }));

    const posting = await createApiClient('https://cv-tailor.example.net', 't').capturePosting(
      capture,
    );

    expect(posting).toEqual({ id: 12, status: 'reviewed', isNew: false });
  });

  it('reports a rejected token', async () => {
    stubFetch(answer(401, { message: 'Unauthenticated.' }));

    await expect(
      createApiClient('https://cv-tailor.example.net', 'stale').capturePosting(capture),
    ).rejects.toMatchObject({ reason: 'token-rejected', status: 401 });
  });

  it('reports a body the backend refused as too large', async () => {
    stubFetch(answer(413, { message: 'Payload Too Large' }));

    await expect(
      createApiClient('https://cv-tailor.example.net', 't').capturePosting(capture),
    ).rejects.toMatchObject({ reason: 'too-large', status: 413 });
  });

  it('names the fields a rejected capture failed on', async () => {
    stubFetch(
      answer(422, {
        message: 'The url field must be a valid URL.',
        errors: { url: ['The url field must be a valid URL.'] },
      }),
    );

    await expect(
      createApiClient('https://cv-tailor.example.net', 't').capturePosting(capture),
    ).rejects.toMatchObject({ reason: 'rejected', status: 422, fields: ['url'] });
  });

  it('reports a backend that is asleep as worth retrying rather than as a refusal', async () => {
    stubFetch(new TypeError('Failed to fetch'));

    const failure = await createApiClient('https://cv-tailor.example.net', 't')
      .capturePosting(capture)
      .catch((thrown: unknown) => thrown);

    expect(failure).toBeInstanceOf(CaptureFailed);
    expect(failure).toMatchObject({ reason: 'unreachable', status: null });
  });
});
