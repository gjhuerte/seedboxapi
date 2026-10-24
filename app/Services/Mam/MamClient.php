<?php

namespace App\Services\Mam;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MamClient
{
    /**
     * Register the IP this request comes from as the session's seedbox IP.
     * MAM answers 200 (Completed / No change), 429 (last change too recent)
     * or 403 (session rejected, e.g. IP or ASN mismatch), and may rotate the
     * mam_id cookie in a Set-Cookie header.
     */
    public function updateSeedboxIp(string $mamId): MamResult
    {
        $response = $this->get(config('seedbox.endpoints.update'), ['Cookie' => 'mam_id='.$mamId]);
        $body = $this->json($response);

        if (! array_key_exists('Success', $body) && ! array_key_exists('msg', $body)) {
            throw new MamException("Unexpected answer from MAM (HTTP {$response->status()}).");
        }

        return new MamResult(
            httpStatus: $response->status(),
            success: (bool) ($body['Success'] ?? false),
            message: isset($body['msg']) ? Str::limit((string) $body['msg'], 250) : null,
            ip: $body['ip'] ?? null,
            asn: isset($body['ASN']) ? (int) $body['ASN'] : null,
            asOrg: $body['AS'] ?? null,
            rotatedCookie: $this->rotatedCookie($response),
        );
    }

    /** The IP/ASN MAM sees, without touching the session (used before a mam_id is set). */
    public function hostInfo(): MamResult
    {
        $response = $this->get(config('seedbox.endpoints.ip'));

        if (! $response->successful()) {
            throw new MamException("MAM IP lookup failed (HTTP {$response->status()}).");
        }

        $body = $this->json($response);

        return new MamResult(
            httpStatus: $response->status(),
            success: null,
            message: null,
            ip: $body['ip'] ?? null,
            asn: isset($body['ASN']) ? (int) $body['ASN'] : null,
            asOrg: $body['AS'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function get(string $url, array $headers = []): Response
    {
        try {
            return Http::timeout(config('seedbox.timeout'))
                ->connectTimeout(min(10, config('seedbox.timeout')))
                ->acceptJson()
                ->withUserAgent('seedboxapi')
                ->withHeaders($headers)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new MamException('Could not reach MAM: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new MamException("MAM did not answer with JSON (HTTP {$response->status()}).");
        }

        return $body;
    }

    private function rotatedCookie(Response $response): ?string
    {
        foreach ($response->toPsrResponse()->getHeader('Set-Cookie') as $header) {
            if (preg_match('/^\s*mam_id=([^;]*)/i', $header, $m)) {
                $value = trim(urldecode($m[1]));

                return $value !== '' && $value !== 'deleted' ? $value : null;
            }
        }

        return null;
    }
}
