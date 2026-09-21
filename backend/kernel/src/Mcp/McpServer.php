<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Mcp;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An MCP server over stdio: JSON-RPC in, JSON-RPC out, one message per line.
 *
 * It calls the API over HTTP with an API key rather than dispatching in-process.
 * That is the whole point: the agent is a CLIENT, and it must go through the
 * same firewall, the same `aud: api_key` claim, the same tenant scope and the
 * same permission checks as any other machine caller (ADR-0007). An in-process
 * version would offer an agent more access than the credential it was given.
 */
final readonly class McpServer
{
    private const string PROTOCOL = '2024-11-05';

    public function __construct(
        private ToolCatalogue $catalogue,
        private HttpClientInterface $http,
        private string $apiBaseUrl,
        private string $apiKey,
        private string $version,
    ) {
    }

    /**
     * @param resource $in
     * @param resource $out
     */
    public function serve(mixed $in, mixed $out): void
    {
        while (($line = fgets($in)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $message */
                $message = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->send($out, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => $e->getMessage()]]);
                continue;
            }

            $response = $this->handle($message);

            // A notification has no id and takes no answer. Replying to one is
            // a protocol error, not a harmless extra line.
            if ($response !== null) {
                $this->send($out, $response);
            }
        }
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>|null
     */
    private function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = \is_string($message['method'] ?? null) ? $message['method'] : '';
        /** @var array<string, mixed> $params */
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($id === null) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'open-enu', 'version' => $this->version],
                ],
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => array_map(
                    static fn (array $tool): array => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'inputSchema' => $tool['inputSchema'],
                    ],
                    $this->catalogue->tools(),
                )],
                'tools/call' => $this->call($params),
                default => throw new \RuntimeException(sprintf('Unknown method "%s".', $method)),
            };
        } catch (\Throwable $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => $e->getMessage()]];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(array $params): array
    {
        $name = \is_string($params['name'] ?? null) ? $params['name'] : '';
        /** @var array<string, mixed> $arguments */
        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        foreach ($this->catalogue->tools() as $tool) {
            if ($tool['name'] !== $name) {
                continue;
            }

            return $this->request($tool, $arguments);
        }

        throw new \RuntimeException(sprintf('No tool "%s".', $name));
    }

    /**
     * @param array{name: string, method: string, path: string, ...} $tool
     * @param array<string, mixed>                                   $arguments
     *
     * @return array<string, mixed>
     */
    private function request(array $tool, array $arguments): array
    {
        $path = $tool['path'];

        foreach ($arguments as $key => $value) {
            if (\is_scalar($value)) {
                $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
            }
        }

        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json']];

        if (\is_array($arguments['body'] ?? null)) {
            $options['json'] = $arguments['body'];
        }

        if (\is_array($arguments['query'] ?? null)) {
            $options['query'] = $arguments['query'];
        }

        $response = $this->http->request($tool['method'], rtrim($this->apiBaseUrl, '/') . $path, $options);
        $status = $response->getStatusCode();

        // A 4xx comes back as tool content with isError, not as a JSON-RPC
        // error: the call itself worked, and the model needs to READ the refusal
        // - "you lack example.manage" is the most useful sentence it can get.
        return [
            'content' => [['type' => 'text', 'text' => $response->getContent(false)]],
            'isError' => $status >= 400,
        ];
    }

    /**
     * @param resource             $out
     * @param array<string, mixed> $message
     */
    private function send(mixed $out, array $message): void
    {
        fwrite($out, json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
        fflush($out);
    }
}
