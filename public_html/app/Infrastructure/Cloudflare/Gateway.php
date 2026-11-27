<?php
namespace App\Infrastructure\Cloudflare;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class Gateway
{
    public function __construct(private string $url, private string $token) {}

    public function call(string $operation, array $payload = []): array
    {
        $response = Http::withToken($this->token)->acceptJson()->timeout(120)
            ->post(rtrim($this->url, '/').'/__cloudflare/database/'.$operation, $payload);
        if (!$response->successful()) {
            // Do not put SQL, bindings, credentials, or response bodies in application logs.
            // 400 and 409 with an error body come from the storage Worker's own checks, which
            // refuse a commit before or instead of applying it. Anything else may be a lost response.
            if (in_array($response->status(), [400, 409], true) && is_string($response->json('error'))) {
                throw new GatewayRejected('Cloudflare database refused the request (HTTP '.$response->status().').');
            }
            throw new RuntimeException('Cloudflare database request failed (HTTP '.$response->status().').');
        }
        return $response->json();
    }
}
