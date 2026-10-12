<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from canned responses.
 *
 * It lets the provider implementation be exercised end to end — request
 * building, the SDK's own deserialisation, result streaming and error
 * translation — without a socket. Nothing here can reach the network.
 */
final class StubTransporter implements ClientInterface
{
    /** @var list<ResponseInterface> */
    private array $responses = [];

    /** @var list<array{method: string, path: string, body: string}> */
    private array $sent = [];

    public function json(int $status, string $body): self
    {
        $this->responses[] = new Response(
            $status,
            ['Content-Type' => 'application/json', 'request-id' => 'req_stub_1'],
            $body,
        );

        return $this;
    }

    public function jsonLines(string $body): self
    {
        $this->responses[] = new Response(
            200,
            ['Content-Type' => 'application/x-jsonl', 'request-id' => 'req_stub_1'],
            Utils::streamFor($body),
        );

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = [
            'method' => strtolower($request->getMethod()),
            'path' => $request->getUri()->getPath(),
            'body' => (string) $request->getBody(),
        ];

        if ($this->responses === []) {
            throw new LogicException('The stub transporter was asked for a response it has not been given.');
        }

        return array_shift($this->responses);
    }

    /**
     * @return array{method: string, path: string, body: string}
     */
    public function lastRequest(): array
    {
        $last = $this->sent[count($this->sent) - 1] ?? null;

        if ($last === null) {
            throw new LogicException('The stub transporter has not been sent anything.');
        }

        return $last;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function lastBody(): array
    {
        $decoded = json_decode($this->lastRequest()['body'], associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    public function sentCount(): int
    {
        return count($this->sent);
    }
}
