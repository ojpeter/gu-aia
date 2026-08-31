<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

use RuntimeException;

/**
 * Fetches a page or document. requirements.md Section 5.1, 5.2.
 *
 * "Crawl, restricted to the University domain and an allow-list of paths."
 * "Login-protected, draft, and archived pages are NEVER crawled."
 *
 * THE ALLOW-LIST IS A SAFETY CONTROL, NOT A POLITENESS SETTING.
 *
 * A crawler that follows whatever it finds will eventually reach a staff
 * member's personal page, a draft, an old prospectus nobody retracted, or a
 * third-party site the University merely links to — and every one of those would
 * then be quoted, with a citation, in the University's name. The restriction is
 * what keeps "published University information" a true description of the corpus
 * rather than an aspiration.
 *
 * So: the host must match exactly (or be a subdomain of) the configured domain,
 * the path must match the allow-list, and a redirect that leaves the allow-list
 * is refused rather than followed. Redirects are the usual way a scope
 * restriction leaks: the URL you approved is not the URL you fetched.
 */
final class HttpFetcher implements Fetcher
{
    /**
     * @param list<string> $allowedPaths path prefixes; empty means nothing is allowed,
     *                                   which is the correct default before Phase 0
     * @param list<string> $excludedPaths checked first, so an exclusion always wins
     */
    public function __construct(
        private readonly string $domain,
        private readonly array $allowedPaths = [],
        private readonly array $excludedPaths = [],
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxBytes = 8 * 1024 * 1024,
        private readonly string $userAgent = 'GU-AIA/1.0 (+https://gu.ac.ug; Directorate of ICT Services)',
    ) {
    }

    public function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        // No http://, no file://, no data:. A corpus document must come from a
        // channel that can be authenticated as the University's.
        if ($parts['scheme'] !== 'https') {
            return false;
        }

        $host = strtolower($parts['host']);
        $domain = strtolower($this->domain);

        if ($host !== $domain && !str_ends_with($host, '.' . $domain)) {
            return false;
        }

        $path = $parts['path'] ?? '/';

        foreach ($this->excludedPaths as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return false;
            }
        }

        // Empty allow-list allows nothing. Before Phase 0 assigns owners and
        // review dates there is no path that may be crawled, and defaulting to
        // "everything" would make forgetting to configure it the same as
        // deciding to crawl the whole site.
        foreach ($this->allowedPaths as $allowed) {
            if (str_starts_with($path, $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function fetch(string $url): FetchedResource
    {
        if (!$this->isAllowed($url)) {
            throw new RuntimeException('Refusing to fetch a URL outside the allow-list: ' . $url);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'user_agent' => $this->userAgent,
                'header' => "Accept: text/html,application/pdf\r\n",
                // Redirects are the usual way a scope restriction leaks: the URL
                // approved is not the URL fetched. Handled explicitly below.
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            throw new RuntimeException('Could not open ' . $url);
        }

        $body = (string) stream_get_contents($handle, $this->maxBytes);
        $meta = stream_get_meta_data($handle);
        fclose($handle);

        [$status, $contentType, $location] = $this->readHeaders($meta['wrapper_data'] ?? []);

        if ($status >= 300 && $status < 400 && $location !== null) {
            $target = $this->resolve($url, $location);

            if (!$this->isAllowed($target)) {
                throw new RuntimeException(
                    'Refusing to follow a redirect out of the allow-list: ' . $url . ' -> ' . $target
                );
            }

            return $this->fetch($target);
        }

        if ($status !== 200) {
            throw new RuntimeException(sprintf('Fetching %s returned HTTP %d.', $url, $status));
        }

        return new FetchedResource($url, $body, $contentType, $status);
    }

    /**
     * @param list<string>|mixed $headers
     *
     * @return array{0: int, 1: string, 2: string|null}
     */
    private function readHeaders(mixed $headers): array
    {
        $status = 0;
        $contentType = '';
        $location = null;

        foreach (is_array($headers) ? $headers : [] as $header) {
            $header = (string) $header;

            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
                continue;
            }
            if (stripos($header, 'content-type:') === 0) {
                $contentType = trim(substr($header, 13));
                continue;
            }
            if (stripos($header, 'location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }

        return [$status, $contentType, $location];
    }

    private function resolve(string $base, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $location;
        }

        $path = $parts['path'] ?? '/';

        return $scheme . '://' . $host . rtrim(dirname($path), '/') . '/' . $location;
    }
}
