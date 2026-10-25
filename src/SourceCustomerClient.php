<?php
declare(strict_types=1);

final class SourceSyncException extends RuntimeException
{
}

final class SourceCustomerClient
{
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 100;
    private const MAX_SYNC_SECONDS = 180;
    private const MAX_RESPONSE_BYTES = 12_000_000;
    private const FIELD_ALIASES = [
        'external_id' => ['id', 'customer_id', 'external_id', 'رقم العميل'],
        'name' => ['name', 'full_name', 'customer_name', 'الاسم'],
        'email' => ['email', 'email_address', 'البريد'],
        'phone' => ['phone', 'phone_number', 'mobile', 'الهاتف'],
        'account_type' => ['account_type', 'type', 'نوع الحساب'],
        'company_url' => ['company_url', 'company_website', 'website', 'domain', 'رابط الشركة'],
        'registered_at' => ['registered_at', 'register_at', 'created_at', 'registration_date', 'تاريخ التسجيل'],
        'email_verified' => ['email_verified', 'verified', 'توثيق البريد'],
        'plan' => ['plan', 'plan_name', 'subscription_plan', 'الباقة'],
        'billing_cycle' => ['billing_cycle', 'دورة الفوترة'],
        'subscription_status' => ['subscription_status', 'sub_status', 'الاشتراك'],
        'payment_status' => ['payment_status', 'حالة الدفع'],
        'last_amount' => ['last_amount', 'amount', 'price', 'آخر مبلغ'],
        'currency' => ['currency', 'last_currency', 'العملة'],
        'source_stage' => ['source_stage', 'stage', 'المرحلة'],
        'services' => ['services', 'linked_services', 'الخدمات المرتبطة'],
        'service_count' => ['service_count', 'services_count', 'عدد الخدمات'],
        'last_activity_at' => ['last_activity_at', 'last_active_at', 'آخر نشاط'],
        'error_count' => ['error_count', 'عدد الأخطاء'],
    ];

    private string $baseUrl;
    private CurlHandle $handle;

    public function __construct(string $sourceUrl, private readonly string $username, private readonly string $password)
    {
        if (!function_exists('curl_init') || !class_exists(DOMDocument::class)) {
            throw new SourceSyncException('موصل المصدر يحتاج إضافات cURL وDOM على الخادم.');
        }
        $this->baseUrl = self::normalizeBaseUrl($sourceUrl);
        $handle = curl_init();
        if (!$handle instanceof CurlHandle) throw new SourceSyncException('تعذر بدء اتصال المصدر.');
        $this->handle = $handle;
        curl_setopt_array($this->handle, self::baseCurlOptions());
    }

    private static function baseCurlOptions(): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36',
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
        ];
    }

    public function fetchCustomers(): array
    {
        $this->login();
        $customers = [];
        $startedAt = microtime(true);
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            if (microtime(true) - $startedAt > self::MAX_SYNC_SECONDS) {
                throw new SourceSyncException('انتهت المهلة المحددة لقراءة صفحات العملاء.');
            }
            $payload = $this->fetchPage($page);
            $rows = self::customerRows($payload);
            if ($rows === null) {
                throw new SourceSyncException('استجابة قائمة العملاء من المصدر ليست بالصيغة المتوقعة.');
            }
            $customers = array_merge($customers, self::normalizeRows($rows));
            if (!self::hasMorePages($payload, $page, count($rows))) {
                return $customers;
            }
        }
        throw new SourceSyncException('تجاوزت صفحات المصدر الحد الأقصى للمزامنة.');
    }

    private static function normalizeRows(array $rows): array
    {
        $customers = [];
        foreach ($rows as $row) {
            if (is_array($row)) $customers[] = self::normalizeCustomer($row);
        }
        if (count($customers) > self::PAGE_SIZE * self::MAX_PAGES) {
            throw new SourceSyncException('تجاوز عدد العملاء الحد الآمن للمزامنة.');
        }
        return $customers;
    }

    private function login(): void
    {
        $loginPage = $this->request($this->baseUrl . '/support_center/');
        $form = self::loginForm($loginPage);
        if ($form === null) {
            throw new SourceSyncException('تعذر العثور على نموذج تسجيل الدخول في المصدر.');
        }
        $actionUrl = self::sameOriginAction($this->baseUrl . '/support_center/', $form['action']);
        $response = $this->request($actionUrl, [
            'csrf_token' => $form['csrf_token'],
            'email' => $this->username,
            'password' => $this->password,
        ], $this->baseUrl . '/support_center/');
        if (self::loginForm($response) !== null) {
            throw new SourceSyncException('رفض المصدر تسجيل الدخول. تحقق من بيانات الحساب.');
        }
    }

    private function fetchPage(int $page): array
    {
        $query = http_build_query([
            'period' => 'all', 'date' => '', 'date_from' => '', 'date_to' => '', 'search' => '',
            'account_type' => 'all', 'plan' => 'all', 'stage' => 'all', 'sort' => 'newest',
            'per_page' => self::PAGE_SIZE, 'page' => $page,
        ]);
        $referer = $this->baseUrl . '/support_center/dashboard.php?period=all&account_type=all&plan=all&stage=all&sort=newest&per_page=' . self::PAGE_SIZE . '&page=' . $page;
        $body = $this->request($this->baseUrl . '/support_center/ajax/customers.php?' . $query, null, $referer);
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceSyncException('استجابة العملاء من المصدر ليست JSON صالحًا.');
        }
        if (!is_array($payload) || (isset($payload['success']) && $payload['success'] === false)) {
            throw new SourceSyncException('لم يسمح المصدر بقراءة قائمة العملاء.');
        }
        return $payload;
    }

    private function request(string $url, ?array $form = null, ?string $referer = null): string
    {
        $currentUrl = $url;
        for ($redirect = 0; $redirect <= 4; $redirect++) {
            [$body, $status, $location] = $this->performRequest($currentUrl, $form, $referer);
            if ($status >= 300 && $status < 400 && $location !== '') {
                if ($redirect === 4) throw new SourceSyncException('تجاوز المصدر عدد التحويلات المسموح.');
                $currentUrl = self::sameOriginAction($currentUrl, $location);
                if (in_array($status, [301, 302, 303], true)) $form = null;
                $referer = $url;
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new SourceSyncException('تعذر إكمال طلب المصدر (HTTP ' . $status . ').');
            }
            return $body;
        }
        throw new SourceSyncException('تعذر إكمال طلب المصدر.');
    }

    private function performRequest(string $url, ?array $form, ?string $referer): array
    {
        $location = '';
        $options = $this->requestOptions($url, $form, $referer);
        $options[CURLOPT_HEADERFUNCTION] = static function (CurlHandle $handle, string $header) use (&$location): int {
            if (str_starts_with(strtolower($header), 'location:')) $location = trim(substr($header, 9));
            return strlen($header);
        };
        curl_setopt_array($this->handle, $options);
        $body = curl_exec($this->handle);
        $status = (int) curl_getinfo($this->handle, CURLINFO_HTTP_CODE);
        if (!is_string($body)) throw new SourceSyncException('تعذر الاتصال بالمصدر.');
        if (strlen($body) > self::MAX_RESPONSE_BYTES) throw new SourceSyncException('استجابة المصدر أكبر من الحد المسموح.');
        return [$body, $status, $location];
    }

    private function requestOptions(string $url, ?array $form, ?string $referer): array
    {
        $headers = self::headers($form !== null, str_contains($url, '/ajax/'));
        if ($form !== null) $headers[] = 'Origin: ' . $this->origin();
        $options = [CURLOPT_URL => $url, CURLOPT_REFERER => $referer, CURLOPT_HTTPHEADER => $headers];
        if ($form === null) {
            $options[CURLOPT_HTTPGET] = true;
            $options[CURLOPT_POST] = false;
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        return $options;
    }

    private function origin(): string
    {
        $parts = parse_url($this->baseUrl);
        return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function headers(bool $isFormPost, bool $isAjax): array
    {
        if ($isFormPost) return ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9,ar;q=0.8', 'Content-Type: application/x-www-form-urlencoded'];
        if ($isAjax) return ['Accept: application/json', 'Accept-Language: en-US,en;q=0.9,ar;q=0.8', 'X-Requested-With: XMLHttpRequest'];
        return ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9,ar;q=0.8', 'Cache-Control: no-cache'];
    }

    private static function normalizeBaseUrl(string $sourceUrl): string
    {
        $parts = parse_url($sourceUrl);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new SourceSyncException('رابط المصدر المحفوظ غير صالح.');
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if (preg_match('#/support_center$#i', $path)) {
            $path = substr($path, 0, -strlen('/support_center'));
        }
        self::assertPublicHost((string) $parts['host']);
        $origin = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $origin . $path;
    }

    private static function assertPublicHost(string $host): void
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolveHost($host);
        if ($addresses === []) throw new SourceSyncException('تعذر التحقق من عنوان المصدر.');
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new SourceSyncException('يجب أن يكون رابط المصدر على خادم عام.');
            }
        }
    }

    private static function resolveHost(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        return array_values(array_filter(array_map(static fn(array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }

    private static function loginForm(string $html): ?array
    {
        $document = new DOMDocument();
        if (!@$document->loadHTML($html)) {
            return null;
        }
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//form') as $form) {
            $token = $xpath->query(".//input[@name='csrf_token']", $form)->item(0);
            $password = $xpath->query(".//input[@type='password']", $form)->item(0);
            $email = $xpath->query(".//input[@name='email']", $form)->item(0);
            if (!($token instanceof DOMElement) || $token->getAttribute('value') === ''
                || !($password instanceof DOMElement) || !($email instanceof DOMElement)) {
                continue;
            }
            $action = $form->getAttribute('action');
            return ['action' => $action, 'csrf_token' => $token->getAttribute('value')];
        }
        return null;
    }

    private static function sameOriginAction(string $baseUrl, string $action): string
    {
        $base = parse_url($baseUrl);
        if (preg_match('#^https://#i', $action)) return self::validateSameOrigin($base, $action);
        $origin = 'https://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($action, '/')) return $origin . $action;
        $directory = rtrim(dirname((string) ($base['path'] ?? '/')), '/');
        return $origin . ($directory === '' ? '' : $directory) . '/' . ltrim($action, '/');
    }

    private static function validateSameOrigin(array $base, string $targetUrl): string
    {
        $target = parse_url($targetUrl);
        if (!is_array($target) || strtolower((string) ($target['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($target['host'] ?? '')) !== strtolower((string) ($base['host'] ?? ''))
            || (int) ($target['port'] ?? 443) !== (int) ($base['port'] ?? 443)
            || isset($target['user']) || isset($target['pass'])) {
            throw new SourceSyncException('رابط تسجيل الدخول في المصدر غير متوقع.');
        }
        return $targetUrl;
    }

    private static function customerRows(array $payload): ?array
    {
        if (array_is_list($payload) && ($payload === [] || is_array($payload[0] ?? null))) {
            return $payload;
        }
        foreach (['customers', 'data', 'rows', 'results', 'items', 'records', 'list'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                if (array_is_list($payload[$key])) {
                    return $payload[$key];
                }
                $nested = self::customerRows($payload[$key]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }

    private static function hasMorePages(array $payload, int $page, int $rowCount): bool
    {
        $hasMore = self::explicitHasMore($payload);
        if ($hasMore !== null) return $hasMore;
        $lastPage = self::lastPage($payload);
        if ($lastPage !== null) return $page < $lastPage;
        return $rowCount === self::PAGE_SIZE;
    }

    private static function explicitHasMore(array $payload): ?bool
    {
        foreach (['has_more', 'hasMore', 'has_next', 'hasNext'] as $key) {
            if (array_key_exists($key, $payload)) return filter_var($payload[$key], FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['pagination', 'meta'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $nested = self::explicitHasMore($payload[$key]);
                if ($nested !== null) return $nested;
            }
        }
        return null;
    }

    private static function lastPage(array $payload): ?int
    {
        foreach (['total_pages', 'totalPages', 'last_page', 'lastPage', 'pages'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) return (int) $payload[$key];
        }
        foreach (['pagination', 'meta'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $nested = self::lastPage($payload[$key]);
                if ($nested !== null) return $nested;
            }
        }
        return null;
    }

    private static function normalizeCustomer(array $record): array
    {
        $indexed = self::indexRecord($record);
        $customer = [];
        foreach (self::FIELD_ALIASES as $field => $keys) {
            $key = self::firstKey($indexed, $keys);
            if ($key !== null) $customer[$field] = self::stringValue($indexed[$key]);
        }
        return $customer;
    }

    private static function indexRecord(array $record): array
    {
        $indexed = [];
        foreach ($record as $key => $value) $indexed[mb_strtolower(trim((string) $key))] = $value;
        return $indexed;
    }

    private static function firstKey(array $record, array $aliases): ?string
    {
        foreach ($aliases as $key) if (array_key_exists($key, $record)) return $key;
        return null;
    }

    private static function stringValue(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
