<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * Does the forum answer? The loopback address first (with the site's Host
 * header), then the real address: a forum in a container often cannot reach
 * its own public URL from inside, and one behind a CDN should not need to.
 */
final class Health
{
    public function __construct(private string $url)
    {
    }

    /** @return array{ok: bool, status: ?int, why: string} */
    public function check(): array
    {
        if (! function_exists('curl_init')) {
            return ['ok' => true, 'status' => null, 'why' => 'curl is missing, so the forum was not checked'];
        }

        $host = (string) parse_url($this->url, PHP_URL_HOST);
        $port = parse_url($this->url, PHP_URL_PORT);
        $path = (string) (parse_url($this->url, PHP_URL_PATH) ?: '/');
        $nonce = 'bridge-health='.bin2hex(random_bytes(4));

        $local = $this->get('http://127.0.0.1'.$path.'?'.$nonce, $host.($port ? ':'.$port : ''), str_starts_with($this->url, 'https://'));

        if ($local['status'] === 200) {
            return ['ok' => true, 'status' => 200, 'why' => 'answered'];
        }

        $remote = $this->get($this->url.'/?'.$nonce, null, false);

        if ($remote['status'] === 200) {
            return ['ok' => true, 'status' => 200, 'why' => 'answered'];
        }

        $status = $remote['status'] ?? $local['status'];

        return ['ok' => false, 'status' => $status, 'why' => $status === null ? 'it could not be reached' : 'it answered '.$status];
    }

    /** @return array{status: ?int} */
    private function get(string $url, ?string $hostHeader, bool $https): array
    {
        $headers = $hostHeader !== null ? ['Host: '.$hostHeader] : [];

        if ($https) {
            $headers[] = 'X-Forwarded-Proto: https';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'Millwright Bridge',
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return ['status' => $body === false || $status === 0 ? null : $status];
    }
}
