<?php

namespace App\Support;

final class Domain
{
    /**
     * Normalize a website URL or bare host to a comparable domain:
     * lower-case host without scheme, port, path or a leading "www.".
     */
    public static function fromUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'http://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim(strtolower($host), '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
