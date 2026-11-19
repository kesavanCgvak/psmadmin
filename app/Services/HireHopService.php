<?php

namespace App\Services;

use App\Exceptions\HireHopApiException;
use App\Models\CompanyIntegration;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HireHop hire-stock reads for one company.
 *
 * Uses GET /modules/stock/asset_assign_list.php (filter=rental). The deprecated
 * /modules/stock/list.php endpoint is not called. The documented row id is
 * MAIN_ID. Live responses identify the same row with ID, and MAIN_ID wins
 * when both are present.
 */
class HireHopService
{
    private const MAX_REQUESTS_PER_MINUTE = 60;

    private const MAX_REQUESTS_PER_SECOND = 3;

    /**
     * Messages from https://myhirehop.com/lang/en-US.js. Unknown codes stay numeric.
     * 327 is the documented rate-limit code and is handled separately.
     *
     * @var array<int, string>
     */
    private const ERROR_MESSAGES = [
        0 => 'Database error, please contact the HireHop team to report this.',
        1 => 'Connection error, please try again.',
        2 => 'User not authorized to perform task.',
        3 => 'Missing parameters',
        9 => 'You are not logged in. Please login again.',
    ];

    private string $token;

    private string $baseUrl;

    private string $listPath;

    private int $timeout;

    public function __construct(private readonly int $companyId)
    {
        $integration = CompanyIntegration::query()
            ->where('company_id', $companyId)
            ->where('integration_type', 'hirehop')
            ->first();

        if (!$integration || !$integration->isConnected()) {
            throw new HireHopApiException(
                'HireHop integration is not configured for this company.',
                422,
                'NOT_CONFIGURED'
            );
        }

        $token = (string) $integration->api_key;
        if ($token === '' || preg_match('/[\r\n\0]/', $token) === 1) {
            throw new HireHopApiException(
                'The HireHop API token is invalid or no longer valid.',
                422,
                'INVALID_API_TOKEN'
            );
        }

        $baseUrl = rtrim((string) config('hirehop.base_url', ''), '/');
        if ($baseUrl === '') {
            throw new HireHopApiException('HireHop base URL is not configured.', 500, 'MISSING_BASE_URL');
        }

        $this->token = $token;
        $this->baseUrl = $baseUrl;
        $this->listPath = '/'.ltrim((string) config('hirehop.stock_list_path', '/modules/stock/asset_assign_list.php'), '/');
        $timeout = (int) config('hirehop.timeout', 15);
        $this->timeout = $timeout > 0 ? $timeout : 15;
    }

    public static function forCompany(int $companyId): self
    {
        return new self($companyId);
    }

    /**
     * One page of rental products. Keyword search uses the documented pq_filter
     * shape with dataIndx TITLE, a field this endpoint returns.
     *
     * @return array{products: list<array<string, mixed>>, page: ?int, total_pages: ?int, total_records: ?int, rows: int}
     */
    public function listRentalProducts(int $page, int $rows, ?string $keyword): array
    {
        $page = max(1, $page);
        $min = max(1, (int) config('hirehop.list_rows_min', 20));
        $max = max($min, (int) config('hirehop.list_rows_max', 200));
        $rows = min($max, max($min, $rows));
        $keyword = trim((string) $keyword);
        $keyword = $keyword === '' ? null : $keyword;

        $cacheKey = $this->cacheKey('list', [$page, $rows, $keyword]);
        $cached = $this->cached($cacheKey);
        if (is_array($cached) && isset($cached['products']) && is_array($cached['products'])) {
            return $cached;
        }

        $query = [
            'filter' => 'rental',
            'del' => '0',
            'page' => $page,
            'rows' => $rows,
        ];
        if ($keyword !== null) {
            $query['pq_filter'] = $this->pqFilter('TITLE', $keyword);
        }

        $payload = $this->get($query);
        $products = $this->productsFromPayload($payload);
        foreach ($products as $product) {
            $this->storeCache($this->cacheKey('item', [$product['hirehop_stock_id']]), $product);
        }

        $result = [
            'products' => $products,
            'page' => $this->optionalInt($payload['page'] ?? null),
            'total_pages' => $this->optionalInt($payload['total'] ?? null),
            'total_records' => $this->optionalInt($payload['totalRecords'] ?? null),
            'rows' => $rows,
        ];
        $this->storeCache($cacheKey, $result);

        return $result;
    }

    /**
     * One rental product by its stock id. Returns null when that id is not in
     * the filtered page. Does not walk the catalogue.
     *
     * asset_assign_list documents MAIN_ID and does not list the pq_filter
     * indexes it accepts. The documented filter is tried first. Live rows use
     * ID and a strict MAIN_ID filter excludes them, so ID is tried only when
     * the first page has no match.
     *
     * @return array<string, mixed>|null
     */
    public function findRentalProduct(string $stockId): ?array
    {
        $stockId = self::canonicalStockId($stockId);
        if ($stockId === null) {
            return null;
        }

        $cacheKey = $this->cacheKey('item', [$stockId]);
        $cached = $this->cached($cacheKey);
        if (is_array($cached) && ($cached['hirehop_stock_id'] ?? null) === $stockId) {
            return $cached;
        }

        $rows = max(
            (int) config('hirehop.list_rows_min', 20),
            (int) config('hirehop.list_rows_default', 20)
        );
        $query = [
            'filter' => 'rental',
            'del' => '0',
            'page' => 1,
            'rows' => $rows,
        ];

        $match = $this->matchStockOnPage($this->get($query + [
            'pq_filter' => $this->pqFilter('MAIN_ID', $this->filterValue($stockId)),
        ]), $stockId);
        if ($match !== null) {
            return $match;
        }

        return $this->matchStockOnPage($this->get($query + [
            'pq_filter' => $this->pqFilter('ID', $this->filterValue($stockId)),
        ]), $stockId);
    }

    public static function canonicalStockId(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value >= 0 ? (string) $value : null;
        }

        if (is_float($value)) {
            if ($value < 0 || floor($value) !== $value) {
                return null;
            }
            $value = sprintf('%.0f', $value);
        }

        if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $value = ltrim($value, '0');

        return $value === '' ? '0' : $value;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(array $query): array
    {
        return $this->send($query, true);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function send(array $query, bool $allowRetry): array
    {
        $this->acquireSlot();

        $url = $this->baseUrl.$this->listPath;
        Log::debug('HireHop stock list request', [
            'company_id' => $this->companyId,
            'method' => 'GET',
            'url' => $url,
            'query' => $query,
            'attempt' => $allowRetry ? 1 : 2,
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-TOKEN' => $this->token,
                    'Accept' => 'application/json',
                ])
                ->get($url, $query);
        } catch (ConnectionException $e) {
            Log::debug('HireHop stock list request failed', [
                'company_id' => $this->companyId,
                'method' => 'GET',
                'url' => $url,
                'query' => $query,
                'error' => $this->redact($e->getMessage()),
            ]);
            $this->logFailure(null, 'network');
            throw new HireHopApiException(
                'HireHop request failed due to a network or timeout issue.',
                422,
                'NETWORK_TIMEOUT'
            );
        }

        Log::debug('HireHop stock list response', [
            'company_id' => $this->companyId,
            'method' => 'GET',
            'url' => $url,
            'query' => $query,
            'status' => $response->status(),
            'x_request_count' => $response->header('X-Request-Count'),
            'x_rate_limit_available' => $response->header('X-RateLimit-Available'),
            'body' => $this->redact($response->body()),
        ]);

        try {
            return $this->interpret($response);
        } catch (HireHopApiException $e) {
            if ($allowRetry && $e->retryable()) {
                $this->pause();

                return $this->send($query, false);
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function interpret(Response $response): array
    {
        $status = $response->status();
        if ($status === 429) {
            $this->logFailure($status, 'rate_limit');
            throw new HireHopApiException(
                'HireHop rate limit reached. Try again shortly.',
                429,
                'HIREHOP_RATE_LIMIT',
                true
            );
        }

        $payload = $this->decode($response);
        $error = is_array($payload) ? $this->errorValue($payload) : null;
        if ($error !== null) {
            throw $this->exceptionForError($error, $status);
        }

        if ($status === 401 || $status === 403) {
            $this->logFailure($status, 'unauthorized');
            throw new HireHopApiException(
                'The HireHop API token is invalid or no longer valid.',
                422,
                'INVALID_API_TOKEN'
            );
        }

        if ($status >= 500) {
            $this->logFailure($status, 'unavailable');
            throw new HireHopApiException('HireHop is unavailable.', 503, 'HIREHOP_UNAVAILABLE');
        }

        if ($status < 200 || $status >= 300) {
            $this->logFailure($status, 'http');
            throw new HireHopApiException('HireHop returned an error.', 502, 'HIREHOP_ERROR');
        }

        if (!is_array($payload)) {
            $this->logFailure($status, 'invalid_json');
            throw new HireHopApiException('HireHop returned an unexpected response.', 502, 'INVALID_RESPONSE');
        }

        return $payload;
    }

    private function exceptionForError(mixed $error, int $status): HireHopApiException
    {
        if (is_int($error) || (is_string($error) && preg_match('/^-?\d+$/', $error) === 1)) {
            $code = (int) $error;
            if ($code === 327) {
                $this->logFailure($status, 'rate_limit');

                return new HireHopApiException(
                    'HireHop rate limit reached. Try again shortly.',
                    429,
                    'HIREHOP_RATE_LIMIT',
                    true
                );
            }

            if ($code === 9 || $status === 401 || $status === 403) {
                $this->logFailure($status, 'unauthorized');

                return new HireHopApiException(
                    'The HireHop API token is invalid or no longer valid.',
                    422,
                    'INVALID_API_TOKEN'
                );
            }

            $known = self::ERROR_MESSAGES[$code] ?? null;
            $message = $known === null
                ? "HireHop returned an error ({$code})."
                : "HireHop returned an error ({$code}: {$known}).";
            $this->logFailure($status, 'hirehop_'.$code);

            return new HireHopApiException($message, 422, 'HIREHOP_ERROR');
        }

        if (is_string($error)) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($error)) ?? '');
            $text = $this->redact($text);
            if (strlen($text) > 300) {
                $text = substr($text, 0, 300);
            }
            $this->logFailure($status, 'hirehop_text');
            $lower = strtolower($text);
            if ($text !== '' && str_contains($lower, 'token') && (str_contains($lower, 'expir') || str_contains($lower, 'invalid'))) {
                return new HireHopApiException(
                    'The HireHop API token is invalid or no longer valid.',
                    422,
                    'INVALID_API_TOKEN'
                );
            }

            return new HireHopApiException(
                $text === '' ? 'HireHop returned an error.' : 'HireHop returned an error: '.$text,
                422,
                'HIREHOP_ERROR'
            );
        }

        $this->logFailure($status, 'hirehop_error');

        return new HireHopApiException('HireHop returned an error.', 422, 'HIREHOP_ERROR');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(Response $response): ?array
    {
        $body = trim($response->body());
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorValue(array $payload): mixed
    {
        if (!array_key_exists('error', $payload) || $payload['error'] === null || $payload['error'] === '' || $payload['error'] === false) {
            return null;
        }

        return $payload['error'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productsFromPayload(array $payload): array
    {
        $rows = $payload['data'] ?? [];
        if ($rows === null) {
            return [];
        }
        if (!is_array($rows)) {
            throw new HireHopApiException('HireHop returned an unexpected response.', 502, 'INVALID_RESPONSE');
        }

        $products = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = self::canonicalStockId($row['MAIN_ID'] ?? $row['ID'] ?? null);
            if ($id === null) {
                continue;
            }

            if (array_key_exists('TYPE', $row) && $row['TYPE'] !== null && $row['TYPE'] !== '' && (int) $row['TYPE'] !== 2) {
                continue;
            }

            $products[] = [
                'hirehop_stock_id' => $id,
                'name' => $this->text($row['TITLE'] ?? null) ?? '',
                'part_number' => $this->text($row['PART_NUMBER'] ?? null),
                'barcode' => $this->text($row['BARCODE'] ?? null),
                'status' => array_key_exists('STATUS', $row) && is_scalar($row['STATUS']) ? $row['STATUS'] : null,
                'type' => array_key_exists('TYPE', $row) && $row['TYPE'] !== null && $row['TYPE'] !== '' && is_scalar($row['TYPE'])
                    ? (int) $row['TYPE']
                    : null,
            ];
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function matchStockOnPage(array $payload, string $stockId): ?array
    {
        $match = null;
        foreach ($this->productsFromPayload($payload) as $product) {
            $this->storeCache($this->cacheKey('item', [$product['hirehop_stock_id']]), $product);
            if ($product['hirehop_stock_id'] === $stockId) {
                $match = $product;
            }
        }

        return $match;
    }

    private function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function pqFilter(string $dataIndx, int|string $value): string
    {
        return json_encode([
            'data' => [
                ['dataIndx' => $dataIndx, 'value' => $value],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function filterValue(string $stockId): int|string
    {
        if (strlen($stockId) <= strlen((string) PHP_INT_MAX) && $stockId <= (string) PHP_INT_MAX) {
            return (int) $stockId;
        }

        return $stockId;
    }

    private function acquireSlot(): void
    {
        $lock = null;
        try {
            $lock = Cache::lock('hirehop:rate-lock:'.$this->companyId, 5);
            $lock->block(2);
        } catch (LockTimeoutException $e) {
            throw new HireHopApiException(
                'HireHop rate limit reached. Try again shortly.',
                429,
                'HIREHOP_RATE_LIMIT'
            );
        } catch (\Throwable $e) {
            $lock = null;
        }

        try {
            $this->recordSlot();
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }

    private function recordSlot(): void
    {
        $key = 'hirehop:rate:'.$this->companyId;
        $now = microtime(true);
        $stamps = Cache::get($key, []);
        if (!is_array($stamps)) {
            $stamps = [];
        }

        $kept = [];
        $inLastSecond = 0;
        foreach ($stamps as $stamp) {
            if (!is_numeric($stamp)) {
                continue;
            }
            $age = $now - (float) $stamp;
            if ($age >= 60) {
                continue;
            }
            $kept[] = (float) $stamp;
            if ($age < 1) {
                $inLastSecond++;
            }
        }

        if (count($kept) >= self::MAX_REQUESTS_PER_MINUTE || $inLastSecond >= self::MAX_REQUESTS_PER_SECOND) {
            throw new HireHopApiException(
                'HireHop rate limit reached. Try again shortly.',
                429,
                'HIREHOP_RATE_LIMIT'
            );
        }

        $kept[] = $now;
        Cache::put($key, $kept, 70);
    }

    private function pause(): void
    {
        $delay = (int) config('hirehop.retry_delay_ms', 5000);
        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    private function cacheKey(string $kind, array $parts): string
    {
        return 'hirehop:'.$kind.':'.$this->companyId.':'.md5((string) json_encode($parts));
    }

    private function cached(string $key): mixed
    {
        if ($this->cacheTtl() < 1) {
            return null;
        }

        return Cache::get($key);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function storeCache(string $key, array $value): void
    {
        $ttl = $this->cacheTtl();
        if ($ttl < 1) {
            return;
        }

        Cache::put($key, $value, $ttl);
    }

    private function cacheTtl(): int
    {
        return max(0, (int) config('hirehop.list_cache_seconds', 60));
    }

    private function redact(string $message): string
    {
        if ($this->token === '') {
            return $message;
        }

        return str_replace($this->token, '[redacted]', $message);
    }

    private function logFailure(?int $status, string $reason): void
    {
        Log::warning('HireHop product request failed', [
            'company_id' => $this->companyId,
            'path' => $this->listPath,
            'response_status' => $status,
            'reason' => $reason,
        ]);
    }
}
