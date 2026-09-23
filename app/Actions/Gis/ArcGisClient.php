<?php

namespace App\Actions\Gis;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ArcGisClient
{
    public function url(string $url, ?string $relative = null): string
    {
        if ($relative !== null) {
            $url = (string) UriResolver::resolve(new Uri($url), new Uri($relative));
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== config('gis.allowed_host') || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('Источник вне разрешённого GIS-портала.');
        }
        $path = $parts['path'] ?? '';
        if (! str_starts_with($path, '/server/rest/') && ! str_starts_with($path, '/portal/sharing/rest/')) {
            throw new RuntimeException('Неподдерживаемый путь GIS-сервиса.');
        }

        return (string) new Uri($url);
    }

    /** @param array<string, mixed> $query */
    public function get(string $url, array $query = []): Response
    {
        $url = $this->url($url);
        foreach ([0, 1] as $slot) {
            $lock = Cache::lock('gis:http:'.$slot, 90);
            if (! $lock->get()) {
                continue;
            }
            try {
                $http = Http::connectTimeout(10)->timeout((int) config('gis.http_timeout'))->withOptions(['allow_redirects' => false]);
                $response = str_ends_with($url, '/query') || str_ends_with($url, '/queryRelatedRecords')
                    ? $http->asForm()->post($url, $query)
                    : $http->get($url, $query);
                if (! $response->successful()) {
                    throw new RuntimeException('GIS HTTP '.$response->status(), $response->status());
                }
                if (strlen($response->body()) > 64 * 1024 * 1024) {
                    throw new RuntimeException('Ответ GIS превысил 64 МБ; уменьшите размер порции.');
                }
                $body = ltrim($response->body());
                if (str_starts_with($body, '{')) {
                    $json = $response->json();
                    if (is_array($json) && isset($json['error'])) {
                        $code = (int) ($json['error']['code'] ?? 0);
                        throw new RuntimeException('ArcGIS error '.$code, $code);
                    }
                }

                return $response;
            } finally {
                $lock->release();
            }
        }
        throw new RuntimeException('GIS занят: два активных запроса.', 429);
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function json(string $url, array $query = []): array
    {
        $data = $this->get($url, $query + ['f' => 'json'])->json();
        if (! is_array($data)) {
            throw new RuntimeException('GIS вернул некорректный JSON.');
        }

        return $data;
    }
}
