<?php

namespace App\Releases;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * One HTTP GET against a release's URL. It only reports what it saw: the
 * page answers with a 2xx status or it does not.
 *
 * The URL is typed by a person, so the request is guarded: http(s) only,
 * the host must resolve to public addresses (unless private hosts are
 * allowed for local testing), the connection is pinned to the address
 * that was checked, and redirects are reported instead of followed.
 */
class HealthChecker
{
    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  host name to IP addresses
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $host) => array_values(array_unique(array_merge(
            gethostbynamel($host) ?: [],
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        )));
    }

    /**
     * @return array{healthy: bool, url: string, http_status: int|null, error: string|null, duration_ms: int, address: string|null}
     */
    public function check(string $url): array
    {
        $result = ['healthy' => false, 'url' => $url, 'http_status' => null, 'error' => null, 'duration_ms' => 0, 'address' => null];
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = trim($parts['host'] ?? '', '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return ['error' => 'Only plain http(s) URLs without credentials can be checked.'] + $result;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if ($addresses === []) {
            return ['error' => "The host {$host} did not resolve."] + $result;
        }
        if (! config('company_os.projects.health_check_allow_private_hosts')) {
            foreach ($addresses as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return ['error' => "The host {$host} resolves to a private or reserved address; it was not contacted.", 'address' => $ip] + $result;
                }
            }
        }

        $address = $addresses[0];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $started = hrtime(true);

        try {
            $response = Http::timeout((int) config('company_os.projects.health_check_timeout_seconds'))
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'CompanyOS-HealthCheck/1.0'])
                // Connect to the address that was checked, not a fresh lookup.
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($address, ':') ? "[{$address}]" : $address)]]])
                ->get($url);
        } catch (Throwable $e) {
            return ['error' => 'Request failed: '.Str::limit($e->getMessage(), 200), 'address' => $address, 'duration_ms' => $this->elapsed($started)] + $result;
        }

        $status = $response->status();
        $error = match (true) {
            $status >= 200 && $status < 300 => null,
            $status >= 300 && $status < 400 => 'Redirected to '.Str::limit((string) $response->header('Location'), 200).'; check that URL instead.',
            default => "Answered with HTTP {$status}.",
        };

        return [
            'healthy' => $error === null,
            'url' => $url,
            'http_status' => $status,
            'error' => $error,
            'duration_ms' => $this->elapsed($started),
            'address' => $address,
        ];
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
