<?php

namespace App\Lib;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PasarGuard
{
    private $token = null;
    private $url = null;
    private $cacheKey = null;
    private $verifySsl = false;
    private array $loginData;

    public $loginStatus = [
        'status' => false,
        'message' => null,
    ];

    public function __construct(array $loginData)
    {
        $this->url = rtrim($loginData['url'], '/');
        $this->verifySsl = $loginData['verify_ssl'] ?? false;
        $this->loginData = $loginData;

        $this->cacheKey = "pasarguard-token-{$loginData['id']}";

        $cachedToken = Cache::get($this->cacheKey);

        if (!empty($cachedToken)) {
            $this->token = $cachedToken;

            $this->loginStatus = [
                'status' => true,
                'message' => 'Token loaded from cache',
            ];

            return;
        }

        $this->login();
    }

    private function login(): void
    {
        $result = $this->request([
            'link' => "{$this->url}/api/admin/token",
            'method' => 'POST',
            'endpoint' => 'login',
            'body_type' => 'form',
            'query' => [
                'username' => $this->loginData['username'],
                'password' => $this->loginData['password'],
            ],
        ]);

        if (!is_array($result)) {
            $this->token = false;

            $this->loginStatus = [
                'status' => false,
                'message' => 'Invalid response from PasarGuard',
            ];

            return;
        }

        if (($result['status'] ?? null) === false || isset($result['detail'])) {
            $this->token = false;

            $this->loginStatus = [
                'status' => false,
                'message' => self::errorMessage($result),
            ];

            return;
        }

        if (empty($result['access_token'])) {
            $this->token = false;

            $this->loginStatus = [
                'status' => false,
                'message' => 'Access token not found',
            ];

            return;
        }

        $this->token = $result['access_token'];

        Cache::put($this->cacheKey, $this->token, now()->addHours(2));

        $this->loginStatus = [
            'status' => true,
            'message' => 'Login successful',
        ];
    }

    public function request(array $data)
    {
        $method = strtoupper($data['method'] ?? 'GET');
        $isLogin = ($data['endpoint'] ?? null) === 'login';
        $query = $data['query'] ?? [];

        // Only an explicit 401 is safe to replay. Never repeat a timed-out write:
        // the panel may already have created or updated the user.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $request = Http::acceptJson()->timeout(30)->connectTimeout(10)
                ->withOptions(['verify' => (bool) $this->verifySsl, 'allow_redirects' => false]);
            if (!$isLogin && !empty($this->token)) {
                $request = $request->withToken($this->token);
            }
            $options = [];
            if ($method === 'GET') {
                $options['query'] = $query;
            } elseif (($data['body_type'] ?? 'json') === 'form') {
                $request = $request->asForm();
                $options['form_params'] = $query;
            } else {
                $request = $request->withBody(
                    is_array($query) ? json_encode($query, JSON_UNESCAPED_UNICODE) : $query,
                    'application/json'
                );
            }

            try {
                $response = $request->send($method, $data['link'], $options);
            } catch (ConnectionException $exception) {
                return ['status' => false, 'message' => 'PasarGuard connection failed or timed out'];
            }

            if ($response->status() === 401 && !$isLogin) {
                $this->clearTokenCache();
                if ($attempt === 0) {
                    $this->login();
                    if ($this->checkConnection()) {
                        continue;
                    }
                    return ['status' => false, 'http_code' => 401, 'message' => $this->loginStatus['message']];
                }
            }

            $decoded = $response->json();
            if (!$response->successful()) {
                return [
                    'status' => false,
                    'http_code' => $response->status(),
                    'message' => self::errorMessage(is_array($decoded) ? $decoded : []),
                ];
            }
            if ($data['raw'] ?? false) {
                return [
                    'status' => true,
                    'http_code' => $response->status(),
                    'content_type' => $response->header('Content-Type'),
                    'body' => $response->body(),
                ];
            }
            if (!is_array($decoded)) {
                return ['status' => false, 'http_code' => $response->status(), 'message' => 'Invalid JSON response'];
            }

            return $decoded;
        }
    }

    public static function errorMessage(array $response): string
    {
        $message = $response['message'] ?? $response['detail'] ?? 'PasarGuard request failed';
        if (is_array($message)) {
            // Validation errors can contain input data. Keep only field names and messages.
            $message = implode('; ', array_map(static function ($error) {
                if (!is_array($error)) return is_scalar($error) ? (string) $error : 'Invalid value';
                return implode('.', (array) ($error['loc'] ?? [])) . ': ' . ($error['msg'] ?? 'Invalid value');
            }, $message));
        }
        return mb_substr((string) $message, 0, 500);
    }

    /* Connection */

    public function checkConnection()
    {
        return !empty($this->token);
    }

    public function getToken()
    {
        return $this->token;
    }

    public function getLoginStatus()
    {
        return $this->loginStatus;
    }

    public function clearTokenCache()
    {
        if (!empty($this->cacheKey)) {
            Cache::forget($this->cacheKey);
        }

        $this->token = null;

        return true;
    }

    /* Groups */

    public function getGroups()
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/groups",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    public function singleGroup($groupId)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/group/{$groupId}",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    /* Users */

    public function getAllUsers()
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/users",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    public function getSimpleUsers()
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/users/simple",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    public function getUser($username)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/{$username}",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    public function getUserById($userId)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/by-id/{$userId}",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    public function createUser(array $params)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => $params,
        ]);
    }

    public function createUserFromTemplate(array $params)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/from_template",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => $params,
        ]);
    }

    public function updateUser(array $params)
    {
        if (!$this->token) {
            return false;
        }

        if (empty($params['username'])) {
            return [
                'status' => false,
                'message' => 'username is required',
            ];
        }

        $username = $params['username'];

        unset($params['username']);

        return $this->request([
            'link' => "{$this->url}/api/user/{$username}",
            'method' => 'PUT',
            'body_type' => 'json',
            'query' => $params,
        ]);
    }

    public function updateUserById($userId, array $params)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/by-id/{$userId}",
            'method' => 'PUT',
            'body_type' => 'json',
            'query' => $params,
        ]);
    }

    public function deleteUser($username)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/{$username}",
            'method' => 'DELETE',
            'body_type' => 'json',
            'query' => [],
        ]);
    }

    public function resetUserUsage($username)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/{$username}/reset",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => [],
        ]);
    }

    public function revokeSubscription($username)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/{$username}/revoke_sub",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => [],
        ]);
    }

    /* Subscription / Config */

    public function getUserConfig($userId, $clientType = 'links')
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/{$userId}/subscription/{$clientType}",
            'method' => 'GET',
            'query' => [],
            'raw' => true,
        ]);
    }

    public function getUserLinks($userId)
    {
        return $this->getUserConfig($userId, 'links');
    }

    public function getUserLinksBase64($userId)
    {
        return $this->getUserConfig($userId, 'links_base64');
    }

    public function getUserClash($userId)
    {
        return $this->getUserConfig($userId, 'clash');
    }

    public function getUserClashMeta($userId)
    {
        return $this->getUserConfig($userId, 'clash_meta');
    }

    public function getUserSingBox($userId)
    {
        return $this->getUserConfig($userId, 'sing_box');
    }

    public function getUserXray($userId)
    {
        return $this->getUserConfig($userId, 'xray');
    }

    public function createUserAndGetConfig(array $params)
    {
        if (!$this->token) {
            return [
                'status' => false,
                'message' => 'PasarGuard login failed',
                'login' => $this->loginStatus,
            ];
        }

        if (empty($params['username'])) {
            return [
                'status' => false,
                'message' => 'username is required',
            ];
        }

        if (empty($params['group_id'])) {
            return [
                'status' => false,
                'message' => 'group_id is required',
            ];
        }

        $username = $params['username'];
        $groupId = $params['group_id'];
        $days = $params['days'] ?? 30;
        $totalGB = $params['total_gb'] ?? 0;
        $clientType = $params['client_type'] ?? 'links';

        $dataLimit = $totalGB > 0
            ? $totalGB * 1024 * 1024 * 1024
            : 0;

        $createParams = [
            'username' => $username,
            'status' => $params['status'] ?? 'active',
            'data_limit' => $params['data_limit'] ?? $dataLimit,
            'data_limit_reset_strategy' => $params['data_limit_reset_strategy'] ?? 'no_reset',
            'group_ids' => array_values(array_map('intval', (array) $groupId)),
            'proxy_settings' => (object) [],
            'auto_delete_in_days' => $params['auto_delete_in_days'] ?? 7,
        ];

        if ($createParams['status'] === 'on_hold') {
            $createParams['expire'] = 0;
            $createParams['on_hold_expire_duration'] = (int) ($params['on_hold_expire_duration'] ?? ($days * 86400));
            $createParams['on_hold_timeout'] = $params['on_hold_timeout'] ?? 0;
        } else {
            $createParams['expire'] = $params['expire'] ?? now()->addDays($days)->utc()->toISOString();
        }

        if (array_key_exists('note', $params)) {
            $createParams['note'] = $params['note'];
        }

        if (array_key_exists('proxy_settings', $params)) {
            $createParams['proxy_settings'] = $params['proxy_settings'];
        }

        $user = $this->createUser($createParams);

        if (!is_array($user)) {
            return [
                'status' => false,
                'message' => 'User creation failed',
                'response' => $user,
            ];
        }

        if (isset($user['status']) && $user['status'] === false) {
            return [
                'status' => false,
                'message' => $user['message'] ?? 'User creation failed',
                'http_code' => $user['http_code'] ?? null,
                'response' => $user,
            ];
        }

        $user = $user['user'] ?? $user;
        $userId = $user['id'] ?? null;

        if (empty($userId)) {
            return [
                'status' => false,
                'message' => 'User created but user id not found in response',
                'response' => $user,
            ];
        }

        return $this->configForCreatedUser($user, $clientType);
    }

    public function configForCreatedUser(array $user, string $clientType = 'links'): array
    {
        $userId = $user['id'];
        $config = $this->getUserConfig($userId, $clientType);

        $subscriptionUrl = $user['subscription_url'] ?? null;
        $configBody = is_array($config) && !empty($config['status']) ? trim((string) ($config['body'] ?? '')) : '';
        if ($configBody === '' && empty($subscriptionUrl)) {
            return [
                'status' => false,
                'message' => 'Config generation failed',
                'user' => $user,
                'config_response' => $config,
            ];
        }

        return [
            'status' => true,
            'user' => $user,
            'user_id' => $userId,
            'config_type' => $clientType,
            'config' => $configBody,
            'subscription_url' => $subscriptionUrl,
        ];
    }
}
