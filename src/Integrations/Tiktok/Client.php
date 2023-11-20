<?php

namespace App\Integrations\Tiktok;

use App\Constants\AccountStatus;
use App\Events\OrderUpdated;
use App\Integrations\AbstractClient;
use App\Models\Account;
use App\Models\Integration;
use App\Models\Region;
use App\Models\Shop;
use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;

class Client extends AbstractClient
{

    protected $version = '1.0';

    protected $format = 'JSON';

    /*
     * This is the lists of methods that needs to be called after the account creation process.
     * This includes things such as outlets, account categories or anything else
     *
     * @var array
     */
    public $postAccountCreation = [
        'getWarehouses' => 'Retrieve store warehouses'
    ];

    /**
     * Client constructor.
     *
     * @param Account $account
     * @param array $config
     */
    public function __construct(Account $account, $config = [])
    {
        $this->url = config('sellstream.tiktok.api_base_uri');

        parent::__construct($account, $config);
    }

    /**
     * Returns the URL for the authorization link for oauth process
     *
     * @param null $region
     * @return string
     */
    public static function getAuthorizationLink($region = null)
    {
        $unique = uniqid();
        if (session('shop')) {
            $unique = session('shop')->getRouteKey();
        }

        $appKey = config('sellstream.tiktok.app_key');

        $state = md5(time() . $unique);
        session()->put('TIKTOK_STATE', $state);

        $url = config('sellstream.tiktok.auth_api_base_uri') . 'oauth/authorize?app_key=' . $appKey . '&state=' . $state;
        return $url;
    }

    /**
     * Generates the token and updates / creates the integration
     *
     * @param $input
     *
     * @return boolean
     * @throws \Exception
     */
    public static function handleRedirect($input)
    {
        $authCode = $input['code'];
        $state = $input['state'];

        if ($state !== session('TIKTOK_STATE')) {
            return false;
        }

        $client = new \GuzzleHttp\Client(['verify' => false]);
        $url = config('sellstream.tiktok.auth_api_base_uri') . 'api/v2/token/get';

        $appKey = config('sellstream.tiktok.app_key');
        $appSecret = config('sellstream.tiktok.app_secret');

        try {
            $params = [
                'query' => [
                    'app_key' => $appKey,
                    'app_secret' => $appSecret,
                    'auth_code' => $authCode,
                    'grant_type' => 'authorized_code'
                ]
            ];

            $response = $client->request('GET', $url, $params);

            if ($response->getStatusCode() === 200) {
                $content = json_decode($response->getBody()->getContents(), true);

                return self::handleAuthenticationResponse($content['data']);
            } else {
                throw new \Exception($response->getBody()->getContents());
            }
        } catch (ClientException $e) {
            set_log_extra('response', 'Unable to request token for Tiktok. Body: ' . $e->getMessage());
            Log::channel('account_log')->error('Unable to request token for Tiktok. Body: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handles the authentication response for logging in and refreshing token
     *
     * @param array $data
     *
     * @return Account
     * @throws \Exception
     */
    private static function handleAuthenticationResponse($data)
    {
        try {
            /** @var Shop $shop */
            $shop = session('shop');

            $username = $data['seller_name'];

            $queryData = [
                'username' => $username,
                'integration_id' => Integration::TIKTOK,
                'region_id'      => Region::GLOBAL,
                'shop_id'        => $shop->id,
                'currency'       => $shop->currency ?: 'SGD',
            ];

            $account = Account::where($queryData)->first();

            $credentials = [
                'access_token' => $data['access_token'],
                'access_token_expire_in' => $data['access_token_expire_in'],
                'refresh_token' => $data['refresh_token'],
                'refresh_token_expire_in' => $data['refresh_token_expire_in'],
            ];

            if (!empty($account)) {
                $credentials = array_merge($account->credentials, $credentials);
            } else {
                $queryData['name'] = $username;
            }

            $account = Account::updateOrCreate($queryData, [
                'credentials' => $credentials,
                'status'      => AccountStatus::ACTIVE()
            ]);

            $account->refresh();

            return $account;
        } catch (\Exception $e) {
            set_log_extra('response', $data);
            throw $e;
        }
    }

    /**
     * Checks to ensure that the credentials are still valid and not expired
     *
     * @param bool $actualCall
     *
     *
     */
    public function isCredentialsValid($actualCall = false)
    {
        return isset($this->account->credentials['access_token_expire_in']) &&
            Carbon::createFromTimestamp($this->account->credentials['access_token_expire_in'])->gte(now()->addDays(2));
    }

    /**
     * Refreshes the token if it's close to expiry
     *
     * @throws \Exception
     */
    private function refreshTokenIfExpiring()
    {
        try {
            if ($this->isCredentialsValid()) {
                // If there's more than 1 day remaining, don't refresh
                return;
            }

            $this->refreshToken();
        } catch (\Throwable $th) {
            $this->disableAccount(AccountStatus::REQUIRE_AUTH());
            set_log_extra('credentials', $this->account->toArray());
            Log::channel('account_log')->info('Tiktok credentials expired and is unable to be refreshed ' . $this->account->id . ' credentials: ' . json_encode($this->account->toArray()));
            throw new \Exception('Tiktok credentials expired and is unable to be refreshed.');
        }
    }

    /**
     * Refreshes the token
     *
     * @throws \Exception
     */
    private function refreshToken()
    {
        $client = new \GuzzleHttp\Client(['verify' => false]);
        $url = config('sellstream.tiktok.auth_api_base_uri') . 'api/v2/token/refresh';

        $appKey = config('sellstream.tiktok.app_key');
        $appSecret = config('sellstream.tiktok.app_secret');

        try {
            $params = [
                'query' => [
                    'app_key' => $appKey,
                    'app_secret' => $appSecret,
                    'refresh_token' => $this->account->credentials['refresh_token'],
                    'grant_type' => 'refresh_token'
                ]
            ];

            $response = $client->request('GET', $url, $params);

            if ($response->getStatusCode() === 200) {
                $content = json_decode($response->getBody()->getContents(), true);

                self::handleAuthenticationResponse($content['data']);
                $this->account->refresh();
            } else {
                throw new \Exception($response->getBody()->getContents());
            }
        } catch (ClientException $e) {
            set_log_extra('response', 'Unable to refresh token for Tiktok. Body: ' . $e->getMessage());
            Log::channel('account_log')->error('Unable to refresh token for Tiktok. Body: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Request call api
     *
     * @param $method
     * @param string $uri
     * @param array $options
     * @param int $maxLogin
     * @return mixed|ResponseInterface
     * @throws \Exception
     */
    public function callRequest($method, $uri = '', array $options = [], $maxLogin = 1)
    {
        try {
            $startRequestTime = now();
            return $this->callRequestAsync($method, $uri, $options)->wait();
        } catch (\Exception $exception) {
            set_log_extra('request_start', $startRequestTime ?? null);
            set_log_extra('request_end', now());
            set_log_extra('method', $method);
            set_log_extra('uri', $uri);
            set_log_extra('options', $options);
            throw $exception;
        }
    }

    /**
     * Request call api
     *
     * @param $method
     * @param string $uri
     * @param array $options
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     * @throws \Exception
     */
    public function callRequestAsync($method, $uri = '', array $options = [])
    {
        $this->refreshTokenIfExpiring();

        if ($this->account->status === AccountStatus::REQUIRE_AUTH()) {
            throw new \Exception("Account is not active");
        }

        $options = array_merge([
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'connect_timeout' => 20,
            'timeout' => 180
        ], $options);

        $apiPath = 'api/' . $uri;
        $appKey = config('sellstream.tiktok.app_key');

        $queryData = isset($options['query']) ? $options['query'] : [];
        $options['query'] = array_merge($queryData, [
            'app_key' => $appKey,
            'timestamp' => time(),
        ]);

        $options['query'] = array_merge($options['query'], [
            'sign' => $this->generateSignature($apiPath, $options['query']),
            'access_token' => $this->account->credentials['access_token'],
        ]);

        $response = parent::requestAsync($method, $this->url . $apiPath, $options);

        return $response->then(function (ResponseInterface $response) use ($method, $uri, $options) {
            if ($response->getStatusCode() === 200) {
                $content =  json_decode($response->getBody(), true);
                if (isset($content['code']) && $content['code'] === 0) {
                    return $content['data'];
                }

                if (isset($content['message'])) {
                    Log::channel('account_log')->info('Tiktok requiest failed. ' . $this->account->id . ' uri: ' . $uri . ' options: ' . json_encode($options) . ' response: ' . json_encode($response->getBody()));
                    set_log_extra('response', json_decode($response->getBody(), true));
                    throw new \Exception($content['message'] ?? 'Tiktok API request failed due to unknown reason.');
                }
            }

            if ($response->getStatusCode() === 401) {
                $this->disableAccount(AccountStatus::REQUIRE_AUTH(), json_decode($response->getBody(), true));
                Log::channel('account_log')->info('Tiktok disabled account. ' . $this->account->id . ' uri: ' . $uri . ' options: ' . json_encode($options) . ' response: ' . json_encode($response->getBody()));
                set_log_extra('response', json_decode($response->getBody(), true));
                throw new \Exception('Tiktok invalid access token.');
            }

            Log::channel('account_log')->info('Tiktok requiest failed. ' . $this->account->id . ' uri: ' . $uri . ' options: ' . json_encode($options) . ' response: ' . json_encode($response->getBody()));
            set_log_extra('response', json_decode($response->getBody(), true));
            throw new \Exception('Tiktok API request failed due to unknown reason.');
        });
    }

    /**
     * Generate hmac-sha256 signature using api path, client secret and query parameters
     *
     * @param $method
     * @param string $uri
     * @param array $options
     *
     * @return string
     * @throws \Exception
     */
    private function generateSignature($path, $queries)
    {
        $appSecret = config('sellstream.tiktok.app_secret');

        $keys = array_keys($queries);
        sort($keys);

        $input = '/' . $path;
        foreach ($keys as $key) {
            $input .= $key . $queries[$key];
        }
        $input = $appSecret . $input . $appSecret;

        $h = hash_hmac("sha256", $input, $appSecret, true);
        if ($h === false) {
            Log::channel('account_log')->info('Unable to generate signature. ' . $this->account->id . ' path: ' . $path . ' queries: ' . json_encode($queries));
            set_log_extra('response', json_decode($queries));
            throw new \Exception('Unable to generate signature.');
        }

        return bin2hex($h);
    }

    /**
     * Cron job for integration set in $job init.php
     * Pull all the available marketplace fee daily based on the orders created that day
     *
     */
    public function retrieveSettlement()
    {
        $orders = $this->account->orders()->whereDate('created_at', now()->subDay()->format('Y-m-d'))->get();

        foreach ($orders as $key => $order) {
            $data = $this->callRequest('GET', 'finance/order/settlements', [
                'query' => [
                    'order_id' => $order->external_id
                ]
            ]);

            if (isset($data['settlement_list'])) {
                $input = [
                    'commission_fee' => 0,
                    'settlement_amount' => 0,
                    'integration_discount'  => 0,
                    'seller_discount' => 0,
                    'seller_shipping_fee' => 0,
                    'integration_shipping_fee' => 0,
                    'actual_shipping_fee' => 0,
                    'transaction_fee' => 0,
                    'service_fee' => 0,
                ];

                foreach ($data['settlement_list'] as $key => $settlement) {
                    $info = $settlement['settlement_info'];

                    $input['commission_fee'] += isset($info['platform_commission']) ? $this->getFloatValue($info['platform_commission']) : 0;
                    $input['settlement_amount'] += isset($info['settlement_amount']) ? $this->getFloatValue($info['settlement_amount']) : 0;
                    $input['integration_discount'] += isset($info['platform_promotion']) ? $this->getFloatValue($info['platform_promotion']) : 0;
                    $input['seller_discount'] += isset($info['sales_fee']) && isset($info['subtotal_after_seller_discounts']) ?  ($this->getFloatValue($info['sales_fee']) - $info['subtotal_after_seller_discounts']) : 0;

                    $input['seller_shipping_fee'] += isset($info['shipping_fee']) ? -$this->getFloatValue($info['shipping_fee']) : 0;
                    $input['integration_shipping_fee'] += isset($info['logistics_reimbursement']) ? $this->getFloatValue($info['logistics_reimbursement']) : 0;
                    $input['actual_shipping_fee'] += isset($info['shipping_fee']) ? -$this->getFloatValue($info['shipping_fee']) : 0;
                    $input['transaction_fee'] += isset($info['transaction_fee']) && isset($info['transaction_fee_refund']) ? ($this->getFloatValue($info['transaction_fee']) - $this->getFloatValue($info['transaction_fee_refund'])) : 0;
                    $input['service_fee'] +=  (isset($info['payment_fee']) ? $this->getFloatValue($info['payment_fee']) : 0) + (isset($info['small_order_fee']) ? $this->getFloatValue($info['small_order_fee']) : 0);
                }

                $order->update($input);
                $logId = $order->getLastLogId();
                event(new OrderUpdated($order, $logId));
            }
        }
    }

    /**
     * Get Tiktok store warehouses
     *
     * @return mixed
     * @throws \Exception
     */
    public function getWarehouses()
    {
        $data = $this->callRequest('GET', "logistics/get_warehouse_list");

        if (isset($data['warehouse_list'])) {
            return collect($data['warehouse_list'])->filter(function ($warehouse) {
                return $warehouse['warehouse_effect_status'] === 1;
            });
        } else {
            set_log_extra('data', $data);
            throw new \Exception('Unable to retrieve warehouses for Tiktok');
        }
    }

    /**
     * Get default warehouse
     *
     * @return array|null
     */
    public function getDefaultWarehouse()
    {
        $warehouses = $this->getWarehouses();
        if (empty($warehouses)) {
            return null;
        }

        return $warehouses->firstWhere('is_default');
    }

    /**
     * Get float value from string
     *
     * @param $value
     *
     * @return float
     */
    private function getFloatValue($value)
    {
        return is_numeric($value) ? (float)$value : 0;
    }
}
