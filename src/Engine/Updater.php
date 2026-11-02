<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * Ask Flarum 2's own web updater to run the migrations.
 *
 * Once Flarum 2's files are in place and the database still records 1.x, every
 * request gets Flarum's "Update Flarum" page, and a POST to it with the
 * database credentials runs the migrations. That is how a forum on a host with
 * no command line gets its database updated. Tried on the loopback address
 * first, so a site behind a CDN is not round-tripped through it.
 */
final class Updater
{
    public function __construct(private Site $site)
    {
    }

    public function migrate(): void
    {
        if (! function_exists('curl_init')) {
            throw new \RuntimeException('this host cannot make HTTP requests, so Flarum 2\'s updater could not be asked to update the database.');
        }

        $db = $this->site->database();
        $fields = ['databaseUsername' => (string) ($db['username'] ?? ''), 'databasePassword' => (string) ($db['password'] ?? '')];
        $url = $this->site->url();
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        $result = $this->post('http://127.0.0.1'.$path, $fields, $host.($port ? ':'.$port : ''));

        if ($result['status'] !== 200 && str_starts_with($url, 'https://')) {
            $result = $this->post($url, $fields, null);
        }

        if ($result['status'] !== 200) {
            throw new \RuntimeException('Flarum 2\'s updater did not update the database ('
                .($result['status'] === null ? 'unreachable: '.$result['error'] : 'it answered '.$result['status'].self::reason($result['body'])).').');
        }
    }

    /** @return array{status: ?int, error: string, body: string} */
    private function post(string $url, array $fields, ?string $hostHeader): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'Millwright Bridge',
            CURLOPT_HTTPHEADER => $hostHeader !== null ? ['Host: '.$hostHeader] : [],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return ['status' => $body === false || $status === 0 ? null : $status, 'error' => curl_error($ch), 'body' => is_string($body) ? $body : ''];
    }

    /** The updater's own words, trimmed: it names the migration that failed. */
    private static function reason(string $body): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return $text === '' ? '' : ': '.mb_substr($text, 0, 300);
    }
}
