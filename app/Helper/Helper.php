<?php

use App\Lib\PasarGuard;
use App\Models\Panels;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

if (!function_exists('PersianNumToEn')) {
    function PersianNumToEn($input)
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '٤', '۵', '٥', '٦', '۶', '۷', '۸', '۹'];
        $english = [0, 1, 2, 3, 4, 4, 5, 5, 6, 6, 7, 8, 9];
        return str_replace($persian, $english, $input);
    }
}

if (!function_exists('loginToSanaie')) {
    function loginToSanaie($data)
    {

        $panel = Panels::where('url', $data['url'])->first();
        if (!is_null($panel) && !is_null($panel->detail)) {
            if (array_key_exists('Expires', $panel->detail)) {
                $expire = $panel->detail['Expires'];
                if (!is_null($expire) && $expire > Carbon::now()) {
                    $Data = [
                        'Domain' => $panel->detail['Domain'],
                        'Path' => $panel->detail['Path'],
                    ];

                    return [
                        'status' => true,
                        'cookies' => $panel->detail['cookies'],
                        'session' => $panel->detail['session'],
                        'raw' => $Data,
                    ];
                }
            }

        }


        $baseUrl = rtrim($data['url'], '/');
        $response = Http::withOptions([
            'verify' => false,
            'curl' => [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ],
        ])
            ->timeout(60)
            ->connectTimeout(20)
            ->asForm()
            ->post(rtrim($baseUrl, '/') . '/login', [
                'username' => $data['username'],
                'password' => $data['password'],
            ]);

        if (!$response->successful()) {
            return [
                'status' => false,
                'message' => 'Login request failed',
                'http_code' => $response->status(),
            ];
        }

        // گرفتن کوکی‌ها (خیلی مهم در x-ui)
        $cookies = $response->cookies();
        $sessionCookie = null;
        $Data = [];

        foreach ($cookies as $cookie) {

            if (str_contains($cookie->getName(), 'session') || str_contains($cookie->getName(), '3x-ui')) {
                $sessionCookie = $cookie->getName() . '=' . $cookie->getValue();
            }
            $expire = !is_null($cookie->getExpires()) ? Carbon::createFromTimestamp($cookie->getExpires())->format('Y-m-d H:i') : null;
            $Data = [
                'Domain' => $cookie->getDomain(),
                'Path' => $cookie->getPath(),
                'Expires' => $expire,
                'session' => $cookie->getName() . '=' . $cookie->getValue(),
                'cookies' => $cookies,
            ];
        }

        if (!is_null($panel)) {
            $panel->detail = array_merge($panel->detail ?? [], $Data);
            $panel->save();
        }


        return [
            'status' => true,
            'cookies' => $cookies,
            'session' => $sessionCookie,
            'raw' => $Data,
        ];
    }
}

if (!function_exists('createUser')) {
    function createUser($data)
    {
        /*
          Expected $data:
          [
            'url' => 'https://your-server.com',
            'session' => 'session_cookie_value',
            'inbound_id' => 1,
            'email' => 'user@example.com',
            'uuid' => 'generated-uuid',
            'total_gb' => 10,
            'expiry_time' => timestamp (ms),
          ]
        */

        $baseUrl = rtrim($data['url'], '/');

        $payload = [
            "id" => $data['inbound_id'],
            "settings" => json_encode([
                "clients" => [
                    [
                        "id" => $data['uuid'],
                        "email" => $data['email'],
                        "limitIp" => 0,
                        "totalGB" => $data['total_gb'] * 1024 * 1024 * 1024,
                        "expiryTime" => $data['expiry_time'],
                        "enable" => true,
                        "tgId" => "",
                        "subId" => ""
                    ]
                ]
            ], JSON_UNESCAPED_SLASHES),
        ];

        $response = Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Cookie' => $data['session'],
            'Content-Type' => 'application/json',
        ])->post($baseUrl . '/panel/api/inbounds/addClient', $payload);

        if (!$response->successful()) {
            return [
                'status' => false,
                'message' => 'Failed to create user',
                'http_code' => $response->status(),
                'response' => $response->body(),
            ];
        }

        return [
            'status' => true,
            'message' => 'User created successfully',
            'data' => $response->json(),
        ];
    }
}

if (!function_exists('createBulkUser')) {
    function createBulkUser($data)
    {
        /*
          Expected $data:
          [
            'url' => 'https://your-server.com',
            'session' => 'session_cookie_value',
            'inbound_id' => 1,
            'email' => 'user@example.com',
            'uuid' => 'generated-uuid',
            'total_gb' => 10,
            'expiry_time' => timestamp (ms),
          ]
        */

        $baseUrl = rtrim($data['url'], '/');

        $payload = [
            "id" => $data['inbound_id'],
            "settings" => json_encode([
                "clients" => $data['clients']
            ], JSON_UNESCAPED_SLASHES),
        ];

        $response = Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Cookie' => $data['session'],
            'Content-Type' => 'application/json',
        ])->post($baseUrl . '/panel/api/inbounds/addClient', $payload);

        if (!$response->successful()) {
            return [
                'status' => false,
                'message' => 'Failed to create user',
                'http_code' => $response->status(),
                'response' => $response->body(),
            ];
        }

        return [
            'status' => true,
            'message' => 'User created successfully',
            'data' => $response->json(),
        ];
    }
}


if (!function_exists('getInbounds')) {

    function getInbounds($data)
    {
        $baseUrl = rtrim($data['url'], '/');

        $response = Http::withOptions([
            'verify' => false,
        ])
            ->timeout(60)
            ->connectTimeout(20)
            ->withHeaders([
                'Cookie' => $data['session'],
            ])->get($baseUrl . '/panel/api/inbounds/list');

        if (!$response->successful()) {
            return [
                'status' => false,
                'message' => 'Failed to fetch inbounds',
                'http_code' => $response->status(),
                'response' => $response->body(),
            ];
        }

        $result = $response->json();
        return [
            'status' => true,
            'inbounds' => $result['obj'] ?? [],
        ];
    }
}

if (!function_exists('getInbound')) {

    function getInbound($data)
    {
        $baseUrl = rtrim($data['url'], '/');

        $response = Http::withOptions([
            'verify' => false,
        ])
            ->timeout(60)
            ->connectTimeout(20)
            ->withHeaders([
                'Cookie' => $data['session'],
            ])->get($baseUrl . "/panel/api/inbounds/get/{$data['id']}");

        if (!$response->successful()) {
            return [
                'status' => false,
                'message' => 'Failed to fetch inbounds',
                'http_code' => $response->status(),
                'response' => $response->body(),
            ];
        }

        $result = $response->json();
        return [
            'status' => true,
            'inbounds' => $result['obj'] ?? [],
        ];
    }
}

if (!function_exists('calculateExtraDiscount')) {
    function calculateExtraDiscount($plan, $pricePerGb)
    {
        $basePrice = $plan->name * $pricePerGb;
        $discount = $plan->discount ?? 0;
        $price = $basePrice - ($basePrice * $discount / 100);
        return [
            'base-price' => $basePrice,
            'price' => $price,
        ];
    }
}

if (!function_exists('calculatePlanDiscount')) {
    function calculatePlanDiscount($plan)
    {
        $basePrice = $plan->price;
        $discount = $plan->discount ?? 0;
        $price = $basePrice - ($basePrice * $discount / 100);
        return [
            'base-price' => $basePrice,
            'price' => $price,
        ];
    }
}

if (!function_exists('headTitle')) {
    function headTitle($title)
    {
        $title = htmlspecialchars(trim((string) $title), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "\u{200F}<b>{$title}</b>\n────────────────────────────────\n";
    }
}

if (!function_exists('rtlMessage')) {
    function rtlMessage($text)
    {
        return preg_replace('/^(?!\x{200F})(?=\S)/mu', "\u{200F}", $text) ?? $text;
    }
}

if (!function_exists('generateConfig')) {

    function generateConfig(array $inbound, array $client, $address)
    {
        $protocol = strtolower($inbound['protocol']);
        $network = $inbound['streamSettings']['network'] ?? 'tcp';
        $security = $inbound['streamSettings']['security'] ?? 'none';

        $port = $inbound['port'];

        switch ($protocol) {

            case 'vless':
                return buildVless($inbound, $client, $address, $port);

            case 'vmess':
                return buildVmess($inbound, $client, $address, $port);

            case 'trojan':
                return buildTrojan($inbound, $client, $address, $port);

            default:
                return null;
        }
    }

    function buildVless($inbound, $client, $server, $port)
    {
        $uuid = $client['uuid'];
        $remark = $client['email'];

        $network = $inbound['streamSettings']['network'] ?? 'tcp';
        $security = $inbound['streamSettings']['security'] ?? 'none';

        $query = [
            'type' => $network,
            'encryption' => 'none',
            'security' => $security,
            'fp' => 'chrome',
        ];

        // WS
        if ($network === 'ws') {
            $query['host'] = $inbound['streamSettings']['wsSettings']['host'] ?? '';
            $query['path'] = $inbound['streamSettings']['wsSettings']['path'] ?? '/';
            $query['alpn'] = implode(',', $inbound['streamSettings']['tlsSettings']['alpn'] ?? []);
        }

        // TCP + TLS
        if ($network === 'tcp' && $security === 'tls') {
            $query['sni'] = $server;
        }

        // gRPC
        if ($network === 'grpc') {
            $query['serviceName'] = $inbound['streamSettings']['grpcSettings']['serviceName'] ?? '';
        }

        // REALITY
        if ($security === 'reality') {
            $query['pbk'] = $inbound['streamSettings']['realitySettings']['publicKey'] ?? '';
            $query['sid'] = $inbound['streamSettings']['realitySettings']['shortId'] ?? '';
            $query['spx'] = '/';
            $query['flow'] = 'xtls-rprx-vision';
        }

        return "vless://{$uuid}@{$server}:{$port}?" . http_build_query($query) . "#{$remark}";
    }

}

if (!function_exists('generateVlessConfig')) {

    function generateVlessConfig($inbound, $clientRemark, $serverAddress)
    {


        $uuid = $clientRemark['id'];
        $port = $inbound['port'];

        $stream = $inbound['streamSettings'] ?? [];

        $network = $stream['network'] ?? 'tcp';
        $security = $stream['security'] ?? 'none';

        $query = [
            'type' => $network,
            'encryption' => 'none',
        ];

        /*
        |--------------------------------------------------------------------------
        | TLS
        |--------------------------------------------------------------------------
        */

        if ($security !== 'none') {

            $query['security'] = $security;

            $tls = $stream['tlsSettings'] ?? [];

            if (!empty($tls['alpn'])) {
                $query['alpn'] = implode(',', $tls['alpn']);
            }

            if (!empty($tls['settings']['fingerprint'])) {
                $query['fp'] = $tls['settings']['fingerprint'];
            }

            if (!empty($tls['serverName'])) {
                $query['sni'] = $tls['serverName'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | WS
        |--------------------------------------------------------------------------
        */

        if ($network === 'ws') {

            $ws = $stream['wsSettings'] ?? [];

            $query['path'] = $ws['path'] ?? '/';

            if (!empty($ws['host'])) {
                $query['host'] = $ws['host'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | gRPC
        |--------------------------------------------------------------------------
        */

        if ($network === 'grpc') {

            $grpc = $stream['grpcSettings'] ?? [];

            if (!empty($grpc['serviceName'])) {
                $query['serviceName'] = $grpc['serviceName'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | TCP
        |--------------------------------------------------------------------------
        */

        if ($network === 'tcp') {

            $tcp = $stream['tcpSettings'] ?? [];

            if (!empty($tcp['header']['type'])) {
                $query['headerType'] = $tcp['header']['type'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Reality
        |--------------------------------------------------------------------------
        */

        if ($security === 'reality') {

            $reality = $stream['realitySettings'] ?? [];

            if (!empty($reality['publicKey'])) {
                $query['pbk'] = $reality['publicKey'];
            }

            if (!empty($reality['shortIds'][0])) {
                $query['sid'] = $reality['shortIds'][0];
            }

            if (!empty($reality['serverNames'][0])) {
                $query['sni'] = $reality['serverNames'][0];
            }
        }

        $queryString = http_build_query($query);

        return "vless://{$uuid}@{$serverAddress}:{$port}?{$queryString}#{$clientRemark['remark']}";
    }
}


function addClient($data)
{
    $serverUrl = $data['serverUrl'];
    $sessionCookie = $data['sessionCookie'];
    $inboundId = $data['inboundId'];
    $email = $data['email'];
    $uuid = $data['uuid'];
    $expiryTimestamp = $data['expiryTimestamp'];
    $limitIp = $data['limitIp'];
    $subId = $data['subId'];
    $totalGB = $data['totalGB'];

    $url = rtrim($serverUrl, '/') . '/panel/api/inbounds/addClient';

    $data = [
        "id" => $inboundId,
        "settings" => json_encode([
            "clients" => [
                [
                    "id" => $uuid,
                    "email" => $email,
                    "limitIp" => $limitIp,
                    "subId" => $subId,
                    "totalGB" => $totalGB,
                    "expiryTime" => $expiryTimestamp,
                    "enable" => true,
                ]
            ]
        ])
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded",
            "Cookie: $sessionCookie"
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            "status" => false,
            "error" => $error
        ];
    }

    curl_close($ch);

    return json_decode($response, true);
}

function updateClient($data)
{
    $serverUrl = $data['serverUrl'];
    $sessionCookie = $data['sessionCookie'];

    $inboundId = $data['inboundId'];

    // شناسه فعلی کاربر داخل پنل
    // اگر UUID را تغییر نمی دهی، oldUuid و uuid یکی باشند
    $oldUuid = $data['oldUuid'] ?? $data['uuid'];

    // UUID جدید
    $uuid = $data['uuid'];

    // ریمارک کاربر در پنل سنایی همان email است
    $email = $data['email'];

    $expiryTimestamp = $data['expiryTimestamp'] ?? 0; // timestamp ms
    $limitIp = $data['limitIp'] ?? 0;
    $subId = $data['subId'] ?? '';
    $totalGB = $data['totalGB'] ?? 0;

    $enable = $data['enable'] ?? true;
    $tgId = $data['tgId'] ?? '';
    $comment = $data['comment'] ?? '';
    $reset = $data['reset'] ?? 0;

    // برای VLESS Reality یا XTLS ممکنه flow داشته باشی
    $flow = $data['flow'] ?? '';

    // برای VMess معمولا security نیاز می شود
    $security = $data['security'] ?? 'auto';

    $url = rtrim($serverUrl, '/') . '/panel/api/inbounds/updateClient/' . urlencode($oldUuid);

    $client = [
        "id" => $uuid,
        "email" => $email,
        "limitIp" => (int)$limitIp,
        "totalGB" => (int)$totalGB,
        "expiryTime" => (int)$expiryTimestamp,
        "enable" => (bool)$enable,
        "tgId" => (string)$tgId,
        "subId" => (string)$subId,
        "comment" => (string)$comment,
        "reset" => (int)$reset,
    ];

    /*
     * فیلدهای اختیاری ولی مهم
     * اگر خالی باشند هم مشکلی ندارد، اما برای بعضی پروتکل ها لازم می شوند.
     */
    if ($flow !== null) {
        $client["flow"] = $flow;
    }

    if ($security !== null) {
        $client["security"] = $security;
    }

    $postData = [
        "id" => $inboundId,
        "settings" => json_encode([
            "clients" => [
                $client
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded",
            "Cookie: $sessionCookie"
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);

        return [
            "status" => false,
            "success" => false,
            "error" => $error
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            "status" => false,
            "success" => false,
            "http_code" => $httpCode,
            "raw" => $response,
            "error" => "Invalid JSON response from panel"
        ];
    }

    $decoded['http_code'] = $httpCode;

    return $decoded;
}

function getClient($data)
{

    $serverUrl = $data['serverUrl'];
    $sessionCookie = $data['sessionCookie'];
    $uuid = $data['uuid'];

    $url = rtrim($serverUrl, '/') . '/panel/api/inbounds/getClientTrafficsById/' . $uuid;
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded",
            "Cookie: $sessionCookie",
            "3x-ui: $sessionCookie"
        ],
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);

        return [
            "status" => false,
            "error" => $error
        ];
    }

    curl_close($ch);

    return json_decode($response, true);
}


function byteToGb($bytes, $precision = 2)
{
    return round($bytes / (1024 * 1024 * 1024), $precision);
}

function gbToByte($gb)
{
    return $gb * 1024 * 1024 * 1024;
}

function daysToMilliseconds($days)
{
    return $days * 24 * 60 * 60 * 1000;
}


function makeSanaeiVlessConfig($streamSettings, string $uuid, string $remark, array $options = []): string
{

    $s = is_string($streamSettings)
        ? json_decode($streamSettings, true)
        : $streamSettings;

    if (!is_array($s)) {
        throw new InvalidArgumentException('streamSettings is not valid JSON.');
    }

    $network = strtolower($s['network'] ?? 'tcp');
    $security = strtolower($s['security'] ?? 'none');

    /*
     * دقیقا مثل خروجی پنل:
     * اگر externalProxy وجود داشته باشد، آدرس و پورت اصلی لینک از externalProxy می آید.
     */
    $address = null;
    $port = null;

    if (!empty($s['externalProxy'][0]['dest'])) {
        $address = $s['externalProxy'][0]['dest'];
    }

    if (!empty($s['externalProxy'][0]['port'])) {
        $port = (int)$s['externalProxy'][0]['port'];
    }

    /*
     * اگر externalProxy نبود، fallback عادی
     */
    if (!$address) {
        $address = $options['address']
            ?? $options['host']
            ?? $s['tlsSettings']['serverName']
            ?? $s['wsSettings']['host']
            ?? $s['wsSettings']['headers']['Host']
            ?? null;
    }

    if (!$port) {
        $port = (int)($options['port'] ?? $s['port'] ?? 0);
    }

    if (!$address) {
        throw new InvalidArgumentException('Address not found.');
    }

    if (!$port) {
        throw new InvalidArgumentException('Port not found.');
    }

    $params = [];

    /*
     * ترتیب را شبیه خروجی پنل نگه می داریم:
     * type, encryption, path, host, security, fp, alpn, sni
     */
    $params['type'] = $network;
    $params['encryption'] = $options['encryption'] ?? 'none';

    /*
     * Network params
     */
    if ($network === 'ws' || $network === 'websocket') {
        $ws = $s['wsSettings'] ?? [];

        if (!empty($ws['path'])) {
            $params['path'] = $ws['path'];
        }

        if (!empty($ws['host'])) {
            $params['host'] = $ws['host'];
        } elseif (!empty($ws['headers']['Host'])) {
            $params['host'] = $ws['headers']['Host'];
        }
    }

    if ($network === 'grpc') {
        $grpc = $s['grpcSettings'] ?? [];

        if (!empty($grpc['serviceName'])) {
            $params['serviceName'] = $grpc['serviceName'];
        }

        if (!empty($grpc['authority'])) {
            $params['authority'] = $grpc['authority'];
        }

        if (!empty($grpc['multiMode'])) {
            $params['mode'] = 'multi';
        }
    }

    if ($network === 'tcp') {
        $tcp = $s['tcpSettings'] ?? [];
        $headerType = $tcp['header']['type'] ?? null;

        if ($headerType === 'http') {
            $params['headerType'] = 'http';

            if (!empty($tcp['header']['request']['path'][0])) {
                $params['path'] = $tcp['header']['request']['path'][0];
            }

            if (!empty($tcp['header']['request']['headers']['Host'][0])) {
                $params['host'] = $tcp['header']['request']['headers']['Host'][0];
            }
        }
    }

    /*
     * TLS params
     */
    if ($security === 'tls') {
        $tls = $s['tlsSettings'] ?? [];

        $params['security'] = 'tls';

        if (!empty($tls['settings']['fingerprint'])) {
            $params['fp'] = $tls['settings']['fingerprint'];
        } elseif (!empty($tls['fingerprint'])) {
            $params['fp'] = $tls['fingerprint'];
        }

        if (!empty($tls['alpn'])) {
            $params['alpn'] = is_array($tls['alpn'])
                ? implode(',', $tls['alpn'])
                : $tls['alpn'];
        }

        if (!empty($tls['serverName'])) {
            $params['sni'] = $tls['serverName'];
        }
    } elseif ($security === 'reality') {
        $reality = $s['realitySettings'] ?? [];

        $params['security'] = 'reality';

        if (!empty($reality['serverNames'][0])) {
            $params['sni'] = $reality['serverNames'][0];
        }

        if (!empty($reality['settings']['publicKey'])) {
            $params['pbk'] = $reality['settings']['publicKey'];
        }

        if (!empty($reality['shortIds'][0])) {
            $params['sid'] = $reality['shortIds'][0];
        }

        if (!empty($reality['settings']['fingerprint'])) {
            $params['fp'] = $reality['settings']['fingerprint'];
        }

        if (!empty($reality['settings']['mldsa65Verify'])) {
            $params['pqv'] = $reality['settings']['mldsa65Verify'];
        }

        /*
         * پنل برای spx مقدار رندوم می سازد.
         * برای خروجی ثابت، اگر spiderX داشتی از همان استفاده کن.
         */
        if (!empty($reality['settings']['spiderX'])) {
            $params['spx'] = $reality['settings']['spiderX'];
        }
    } else {
        $params['security'] = 'none';
    }

    $query = http_build_query(
        array_filter($params, function ($v) {
            return $v !== null && $v !== '';
        }),
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    return 'vless://' . rawurlencode($uuid)
        . '@' . $address . ':' . $port
        . '?' . $query
        . '#' . rawurlencode($remark);
}


function uploadProtocolFile($basePath, $fileParam)
{

    if (!$fileParam || !$fileParam->isValid()) {
        return '/new-image/images/test.png';
    }

    $extension = strtolower($fileParam->guessClientExtension() ?? $fileParam->getClientOriginalExtension());

    if ($extension === 'bin') {
        $extension = 'jpg';
    }

    $allowedExtensions = ['png', 'jpg', 'jpeg'];

    if (!in_array($extension, $allowedExtensions, true)) {
        return '/new-image/images/test.png';
    }

    $originalName = pathinfo($fileParam->getClientOriginalName(), PATHINFO_FILENAME);

    $fileName = preg_replace('/[^A-Za-z0-9\-_]/', '', $originalName);

    if (empty($fileName)) {
        $fileName = 'image';
    }

    $newFileName = $fileName . '_' . time() . '_' . rand(1000, 9999) . '.' . $extension;

    $destinationPath = public_path('guide');

    if (!is_dir($destinationPath)) {
        mkdir($destinationPath, 0755, true);
    }

    $fileParam->move($destinationPath, $newFileName);

    return "upload/$basePath/" . $newFileName;
}


function getConfigDetail($order)
{
    $panel = Panels::find($order->panel_id);

    if (is_null($panel)) {
        return [
            'status' => false,
            'msg' => "⚠️ <b>خطا در دریافت اطلاعات سرویس</b>\n\nپنل مربوط به این سفارش پیدا نشد. لطفا با پشتیبانی در ارتباط باشید."
        ];
    }
    if ($panel->system_type == 'pasarguard') {
        $pasarGuard = new PasarGuard([
            'url' => $panel->url,
            'username' => $panel->username,
            'password' => $panel->password,
            'id' => $panel->id,
        ]);
        $result = $pasarGuard->getUserById($order->uid);

        if (!is_array($result) || ($result['status'] ?? null) === false) {
            return [
                'status' => false,
                'msg' => '⚠️ اطلاعات سرویس از پنل دریافت نشد.',
                'provider_response' => $result,
            ];
        }

        $totalBytes = is_numeric($result['data_limit'] ?? null) ? (float) $result['data_limit'] : 0;
        $usedBytes = is_numeric($result['used_traffic'] ?? null) ? (float) $result['used_traffic'] : 0;
        $totalGb = byteToGb($totalBytes);
        $totalUsed = byteToGb($usedBytes);
        $expire = optional($order->expire_at)->format('Y-m-d H:i:s');
        if (!empty($result['expire'])) {
            try {
                $expire = Carbon::parse($result['expire'])
                    ->timezone(config('app.timezone'))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                // Keep the local expiry when the provider returns an invalid date.
            }
        }
        $left = $totalGb - $totalUsed;
        $code = $order->detail['code'] ?? null;
        // All-country PasarGuard orders use their subscription URL. Fetching a
        // multi-link config here is unnecessary and can delay the Telegram callback.
        if ((int) $order->inbound_id !== 0) {
            $configResult = $pasarGuard->getUserConfig($order->uid);
            $code = is_array($configResult) && ($configResult['status'] ?? false)
                ? ($configResult['body'] ?? null)
                : $code;
        }
        $providerStatus = $result['status'] ?? null;
    } else {
        $result = clientGetSanaieSinlgeDetail($panel, $order);
        if (!($result['remote_available'] ?? false)) {
            return [
                'status' => false,
                'msg' => '⚠️ اطلاعات سرویس از پنل دریافت نشد.',
                'provider_response' => $result,
            ];
        }
        $totalGb = $result['totalGb'];
        $totalUsed = $result['totalUsed'];
        $left = $result['left'];
        $code = $order->detail['code'] ?? null;
        $expire = $order->expire_at;
        $providerStatus = $result['provider_status'] ?? null;
    }
    $left = $left > 0 ? $left : 0;
    return [
        'status' => true,
        'data' => [
            'totalGb' => $totalGb,
            'totalUsed' => $totalUsed,
            'left' => $left,
            'expire' => $expire,
            'code' => $code,
            'provider_status' => $providerStatus,
            'remote_available' => true,
            'group_ids' => $panel->system_type === 'pasarguard' ? ($result['group_ids'] ?? null) : null,
        ]
    ];
}

function clientGetSanaieSinlgeDetail($panel, $order)
{
    $totalGb = 'اطلاعات یافت نشد';
    $totalUsed = 'اطلاعات یافت نشد';
    $left = 'اطلاعات یافت نشد';
    $expireTime = 'اطلاعات یافت نشد';
    $providerStatus = null;
    $remoteAvailable = false;

    $loginData = [
        'username' => $panel->username,
        'password' => $panel->password,
        'url' => $panel->url,
    ];

    $session = loginToSanaie($loginData);

    if (!empty($session['status'])) {
        $clientRequestData = [
            'sessionCookie' => $session['session'],
            'serverUrl' => $panel->url,
            'uuid' => $order->uid,
        ];

        $clientData = getClient($clientRequestData);

        if (!empty($clientData['success']) && !empty($clientData['obj'][0])) {
            $client = $clientData['obj'][0];

            $totalGbValue = byteToGb($client['total'] ?? 0);
            $totalUsedValue = byteToGb($client['allTime'] ?? 0);

            $totalGb = $totalGbValue;
            $totalUsed = $totalUsedValue;

            if (is_numeric($totalGbValue) && is_numeric($totalUsedValue)) {
                $left = max(0, $totalGbValue - $totalUsedValue);
            }

            if (!empty($client['expiryTime']) && $client['expiryTime'] > 0) {
                $expireTime = Carbon::createFromTimestampMs($client['expiryTime'])
                    ->format('Y-m-d H:i');
            }

            $providerStatus = ($client['enable'] ?? true) ? 'active' : 'disabled';
            $remoteAvailable = true;
        }
    }

    return [
        'totalGb' => $totalGb,
        'totalUsed' => $totalUsed,
        'left' => $left,
        'expireTime' => $expireTime,
        'provider_status' => $providerStatus,
        'remote_available' => $remoteAvailable,
    ];

}
