<?php

namespace App\Integrations\Tiktok;

use App\Constants\FulfillmentStatus;
use App\Constants\FulfillmentType;
use App\Constants\IntegrationSyncData;
use App\Constants\OrderType;
use App\Constants\PaymentStatus;
use App\Constants\ShipmentStatus;
use App\Integrations\AbstractOrderAdapter;
use App\Integrations\TransformedAddress;
use App\Integrations\TransformedOrder;
use App\Integrations\TransformedOrderItem;
use App\Models\Account;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class OrderAdapter extends AbstractOrderAdapter
{
    /**
     * Retrieves a single order
     *
     * @param $externalId
     * @param array $options
     * @return Order|void|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function get($externalId, $options = ['deduct' => true])
    {
        return $this->getOrderDetails([$externalId], $options);
    }

    /**
     * Retrieves order details from Tiktok
     *
     * @param array $externalIds
     * @return array $orders
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function getOrderDetails($externalIds, $options)
    {
        try {
            $data = $this->client->callRequest('POST', "orders/detail/query", [
                'json' => [
                    'order_id_list' => $externalIds
                ]
            ]);

            $splitData = $this->client->callRequest('POST', "fulfillment/order_split/verify", [
                'json' => [
                    'order_id_list' => collect($externalIds)->map(function ($externalId) {
                        return (int)$externalId;
                    })
                ]
            ]);
            $splitList = $splitData['result_list'];

            $orders = [];
            foreach ($data['order_list'] as $orderData) {
                try {
                    $orderSplitResult = collect($splitList)->firstWhere('order_id', '=', $orderData['order_id']);
                    if ($orderSplitResult) {
                        $orderData['can_split'] = $orderSplitResult['verify_order_result'];
                    } else {
                        $orderData['can_split'] = false;
                    }

                    $warehouseId = $this->account->getSetting(['account', 'warehouse']);
                    if (empty($warehouseId)) {
                        $defaultWarehouse = $this->client->getDefaultWarehouse();
                        $warehouseId = $defaultWarehouse['warehouse_id'] ?? null;
                    }

                    if (!empty($warehouseId) && $orderData['warehouse_id'] !== $warehouseId) {
                        continue;
                    }

                    $orderData = $this->transformOrder($orderData);
                } catch (\Exception $e) {
                    set_log_extra('order', $orderData);
                    throw $e;
                }
                $orders[] = $this->handleOrder($orderData, $options);
            }

            return $orders;
        } catch (\Exception $e) {
            set_log_extra('order external ids', $externalIds);
            throw $e;
        }
    }

    /**
     * Import all orders
     *
     * @param array $options
     * @return void
     * @throws \Exception
     */
    public function import($options = ['deduct' => false])
    {
        // This is so it wont create new notifications
        $options['import'] = true;
        if (!isset($options['deduct'])) {
            $options['deduct'] = false;
        }

        $parameters = [
            'sort_by' => 'CREATE_TIME',
            'sort_type' => 1,
            'page_size' => 40,
        ];
        $this->fetchOrders($options, $parameters);
    }

    /**
     * Incremental order sync
     *
     * @return mixed
     * @throws \Exception
     */
    public function sync()
    {
        $options['import'] = false;
        $options['deduct'] = $this->account->hasFeature(['orders', 'deduct_inventory']);
        $now = now(); // Set here to make sure the now datetime is not after fetch all orders

        $parameters = [
            'update_time_from' => $this->account->getSyncData(IntegrationSyncData::SYNC_ORDERS(), now(), true)->subMinutes(10)->timestamp, // Added sub 10 mins for safety purpose
            'sort_by' => 'CREATE_TIME',
            'sort_type' => 1,
            'page_size' => 40
        ];
        $this->fetchOrders($options, $parameters);

        $this->account->setSyncData(IntegrationSyncData::SYNC_ORDERS(), $now);
    }


    /**
     * This is used by both import and sync as their code is the same, the only difference is the timestamps
     *
     * @param $options
     * @param $parameters
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    private function fetchOrders($options, $parameters)
    {
        do {
            $data = $this->client->callRequest('POST', "orders/search", [
                'json' => $parameters
            ]);

            if (isset($data['order_list'])) {
                $externalOrderIds = collect($data['order_list'])->map(function ($externalOrder) {
                    return $externalOrder['order_id'];
                });
                $nextPageExist = $data['more'];

                $parameters['cursor'] = $data['next_cursor'];
                $orders = $this->getOrderDetails($externalOrderIds, $options);
            } else {
                $externalOrderIds = [];
                $nextPageExist = false;
            }
        } while (!empty($externalOrderIds) && $nextPageExist);
    }

    /**
     * @inheritDoc
     * @throws \Exception
     */
    public function transformOrder($order)
    {
        $externalId = $order['order_id'];
        $externalNumber = $order['order_id'];
        $externalSource = $this->account->integration->name;

        $shipping = $order['recipient_address'];

        $customerName = $shipping['name'] ?? null;
        $customerEmail = null;

        // format address function
        $formatAddress = function ($data, $phone, $districtInfo) {
            $districtInfo = collect($districtInfo);

            $cityInfo = $districtInfo->first(function ($info) {
                return $info['address_level_name'] == 'city';
            });
            $city = !empty($cityInfo) ? $cityInfo['address_name'] : null;

            $stateInfo = $districtInfo->first(function ($info) {
                return $info['address_level_name'] == 'state';
            });
            $state = !empty($stateInfo) ? $stateInfo['address_name'] : null;

            $countryInfo = $districtInfo->first(function ($info) {
                return $info['address_level_name'] == 'country';
            });
            $country = !empty($countryInfo) ? $countryInfo['address_name'] : null;

            return new TransformedAddress(
                null,
                '',
                $data['address_line_list'][0] ?? '',
                $data['address_line_list'][1] ?? '',
                $data['address_line_list'][2] ?? '',
                $data['address_line_list'][3] ?? '',
                $data['address_line_list'][4] ?? '',
                $city,
                $data['zipcode'],
                $state,
                $country,
                $phone
            );
        };

        // To remove any if it's empty
        $phone = array_filter([$shipping['phone']]);
        $shippingAddress = $formatAddress($shipping, $phone, $order['district_info_list']);

        // No billing address in Tiktok, so use shipping address
        $billingAddress = $shippingAddress;

        $shipByDate = isset($order['delivery_sla']) && !empty($order['delivery_sla']) ? Carbon::createFromTimestampMs($order['delivery_sla']) : null;

        if ($order['order_status'] == 100) {
            // Order status is UNPAID
            $paymentStatus = PaymentStatus::PENDING_PAYMENT();
        } else {
            $paymentStatus = PaymentStatus::PAID();
        }

        $currency = $this->account->currency;

        $paymentInfo = $order['payment_info'];

        $integrationDiscount = $paymentInfo['platform_discount'] ?? 0;
        $sellerDiscount = $paymentInfo['seller_discount'] ?? 0;
        $shippingFee = filter_var($paymentInfo['shipping_fee'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $integrationShippingFee = $paymentInfo['shipping_fee_platform_discount'];
        $sellerShippingFee = $paymentInfo['shipping_fee_seller_discount'];
        $tax = $paymentInfo['taxes'];
        $tax2 = 0;
        $commission = 0;
        $transactionFee = 0;
        $totalVoucher = 0;
        $subtotal = 0;

        $paymentMethod = $order['payment_method_name'];

        if ($order['fulfillment_type'] == 0) {
            $fulfillmentType = FulfillmentType::REQUIRES_SHIPPING();
        } else {
            $fulfillmentType = FulfillmentType::NO_SHIPPING();
        }

        switch ($order['order_status']) {
            case 100: // UNPAID
                $fulfillmentStatus = FulfillmentStatus::DOCUMENTATION();
                break;

            case 111: // AWAITING SHIPMENT
                $fulfillmentStatus = FulfillmentStatus::TO_SHIP();
                break;

            case 112: // AWAITING COLLECTION
                $fulfillmentStatus = FulfillmentStatus::READY_TO_SHIP();
                break;

            case 121: // IN TRANSIT
                $fulfillmentStatus = FulfillmentStatus::SHIPPED();
                break;

            case 114: // PARTIALLY SHIPPED:
                $fulfillmentStatus = FulfillmentStatus::PARTIALLY_SHIPPED();
                break;

            case 122: // DELIVERED
                $fulfillmentStatus = FulfillmentStatus::DELIVERED();
                break;

            case 130: // COMPLETED
                if (isset($order['cancel_reason']) && !empty($order['cancel_reason'])) {
                    if (isset($order['paid_time']) && !empty($order['paid_time'])) {
                        $fulfillmentStatus = FulfillmentStatus::REFUNDED();
                    } else {
                        $fulfillmentStatus = FulfillmentStatus::CANCELLED();
                    }
                } else {
                    $fulfillmentStatus = FulfillmentStatus::COMPLETED();
                }
                break;

            case 140: // CANCELLED
                $fulfillmentStatus = FulfillmentStatus::CANCELLED();
                break;

            default:
                $fulfillmentStatus = FulfillmentStatus::CANCELLED();
                break;
        }

        $buyerRemarks = $order['buyer_message'];

        $type = OrderType::NORMAL();

        $orderPlacedAt = Carbon::createFromTimestampMs($order['create_time']);
        $orderUpdatedAt = Carbon::createFromTimestamp($order['update_time']);

        if (isset($order['paid_time']) && !empty($order['paid_time'])) {
            $orderPaidAt = Carbon::createFromTimestampMs($order['paid_time']);
        } else {
            $orderPaidAt = null;
        }

        $data = [
            'can_split' => $order['can_split'] ?? false,
            'delivery_option' => $order['delivery_option']
        ];

        $items = [];
        foreach ($order['order_line_list'] as $item) {
            $itemExternalId = $item['order_line_id'];
            $itemName = $item['product_name'];
            $externalProductId = $item['product_id'];
            $sku = $item['seller_sku'];
            $variationName = $item['sku_name'];
            $variationSku = $sku;
            $quantity = 1;

            $itemIntegrationDiscount = $item['platform_discount'];
            $itemSellerDiscount = $item['seller_discount'];

            $itemShippingFee = 0;

            $itemTax = 0;
            $itemTax2 = 0;
            $tax += $itemTax;

            $itemPrice = $item['sale_price'] - $itemTax;
            $itemSubTotal = $itemPrice;

            switch ($item['display_status']) {
                case 100: // UNPAID
                    $itemFulfillmentStatus = FulfillmentStatus::DOCUMENTATION();
                    break;

                case 111: // AWAITING SHIPMENT
                    $itemFulfillmentStatus = FulfillmentStatus::TO_SHIP();
                    break;

                case 112: // AWAITING COLLECTION
                    $itemFulfillmentStatus = FulfillmentStatus::READY_TO_SHIP();
                    break;

                case 121: // IN TRANSIT
                    $itemFulfillmentStatus = FulfillmentStatus::SHIPPED();
                    break;

                case 114: // PARTIALLY SHIPPED:
                    $itemFulfillmentStatus = FulfillmentStatus::PARTIALLY_SHIPPED();
                    break;

                case 122: // DELIVERED
                    $itemFulfillmentStatus = FulfillmentStatus::DELIVERED();
                    break;

                case 130: // COMPLETED
                    if (isset($item['cancel_reason']) && !empty($item['cancel_reason'])) {
                        if (isset($order['paid_time']) && !empty($order['paid_time'])) {
                            $itemFulfillmentStatus = FulfillmentStatus::REFUNDED();
                        } else {
                            $itemFulfillmentStatus = FulfillmentStatus::CANCELLED();
                        }
                    } else {
                        $itemFulfillmentStatus = FulfillmentStatus::COMPLETED();
                    }
                    break;

                case 140: // CANCELLED
                    $itemFulfillmentStatus = FulfillmentStatus::CANCELLED();
                    break;

                default:
                    $itemFulfillmentStatus = FulfillmentStatus::CANCELLED();
                    break;
            }

            $itemGrandTotal = $item['sale_price'] - $itemIntegrationDiscount - $itemSellerDiscount;
            $itemBuyerPaid = $itemGrandTotal;

            $subtotal += $itemSubTotal;

            $shipmentProvider = $item['shipping_provider_name'] ?? null;
            $shipmentType = null;
            $shipmentMethod = null;
            $trackingNumber = $item['tracking_number'] ?? null;

            $returnStatus = null;

            $costOfGoods = null;

            $actualShippingFee = 0;
            $itemData = [
                'package_id' => $item['package_id'] ?? null,
                'shipping_provider_id' => $item['shipping_provider_id'] ?? null
            ];

            $items[] = new TransformedOrderItem(
                $itemExternalId,
                $externalProductId,
                $itemName,
                $sku,
                $variationName,
                $variationSku,
                $quantity,
                $itemPrice,
                0,
                $itemIntegrationDiscount,
                0,
                0,
                $itemSellerDiscount,
                0,
                0,
                $itemShippingFee,
                $itemTax,
                $itemTax2,
                $itemSubTotal,
                $itemGrandTotal,
                $itemBuyerPaid,
                $itemFulfillmentStatus,
                $shipmentProvider,
                $shipmentType,
                $shipmentMethod,
                $trackingNumber,
                $returnStatus,
                $costOfGoods,
                $actualShippingFee,
                $itemData
            );
        }
        /*
         * buyer paid need to deduct the voucher
         * eg - grand total 1.50
         * if buyer use voucher 0.50 then need to deduct from the grand total
         * which balance is 1 will be paid by the buyer
         * https://prnt.sc/tjhs2v
         * */
        $grandTotal = $subtotal - $totalVoucher + $shippingFee + $tax;
        $buyerPaid = $paymentStatus->equals(PaymentStatus::PAID()) ? $grandTotal : 0;

        $order = new TransformedOrder(
            $externalId,
            $externalSource,
            $externalNumber,
            null,
            null,
            $customerName,
            $customerEmail,
            $shippingAddress,
            $billingAddress,
            $shipByDate,
            $currency,
            0,
            $integrationDiscount,
            0,
            0,
            $sellerDiscount,
            0,
            0,
            $shippingFee,
            $integrationShippingFee,
            $sellerShippingFee,
            0,
            $tax,
            $tax2,
            $commission,
            $transactionFee,
            0,
            null,
            $subtotal,
            $grandTotal,
            $buyerPaid,
            null,
            $paymentStatus,
            $paymentMethod,
            $fulfillmentType,
            $fulfillmentStatus,
            $buyerRemarks,
            $type,
            $data,
            $orderPlacedAt,
            $orderUpdatedAt,
            $orderPaidAt,
            $items
        );
        return $order;
    }


    /**
     * @inheritDoc
     */
    public function availableActions(Order $order)
    {
        // General actions are those that can be called regardless of status
        $general = [''];

        // These are status specific in which they depend on the status of the order
        $statusSpecific = [];

        if (
            $order->fulfillment_status === FulfillmentStatus::TO_SHIP()->getValue() ||
            $order->fulfillment_status === FulfillmentStatus::DOCUMENTATION()->getValue()
        ) {
            $statusSpecific[] = 'initInfo';
            $statusSpecific[] = 'fulfillment';

            // Number of items in order must greater or equal to 2.
            if ($order->items->count() >= 2 && (isset($order->data['can_split']) && $order->data['can_split'])) {
                $statusSpecific[] = 'split';
            }
        } elseif ($order->fulfillment_status === FulfillmentStatus::READY_TO_SHIP()->getValue()) {
            $statusSpecific[] = 'initInfo';
            $statusSpecific[] = 'bill';
        } else if ($order->fulfillment_status === FulfillmentStatus::REQUEST_CANCEL()->getValue()) {
            $statusSpecific[] = 'cancellation';
        } else if ($order->fulfillment_status === FulfillmentStatus::RETRY_SHIP()->getValue()) {
            $statusSpecific[] = 'initInfo';
            $statusSpecific[] = 'fulfillment';
        } else if ($order->fulfillment_status === FulfillmentStatus::SHIPPED()->getValue()) {
            $statusSpecific[] = 'bill';
        }

        if ($order->fulfillment_status <= FulfillmentStatus::READY_TO_SHIP()->getValue()) {
            $statusSpecific[] = 'cancel';
            $statusSpecific[] = 'reasons';
        }

        return array_merge($general, $statusSpecific);
    }

    /**
     * Retrieves all the cancellation reasons for the order
     *
     * @return mixed
     * @throws \Exception
     */
    public function reasons(Order $order)
    {
        try {
            switch ($order->fulfillment_status) {
                case FulfillmentStatus::PENDING()->getValue():
                case FulfillmentStatus::DOCUMENTATION()->getValue():
                    $reverseActionType = 1;
                    $reasonType = 1;
                    $fulfillmentStatus = 1;
                    break;

                case FulfillmentStatus::TO_SHIP()->getValue():
                    $reverseActionType = 1;
                    $reasonType = 1;
                    $fulfillmentStatus = 2;
                    break;

                case FulfillmentStatus::READY_TO_SHIP()->getValue():
                    $reverseActionType = 1;
                    $reasonType = 1;
                    $fulfillmentStatus = 3;
                    break;

                default:
                    $reverseActionType = 1;
                    $reasonType = 1;
                    $fulfillmentStatus = 1;
                    break;
            }

            $data = $this->client->callRequest('GET', "reverse/reverse_reason/list", [
                'query' => [
                    'reverse_action_type' => $reverseActionType,
                    'reason_type' => $reasonType,
                    'fulfillment_status' => $fulfillmentStatus
                ]
            ]);

            return collect($data['reverse_reason_list'])->reduce(function ($allReasons, $reason) {
                $allReasons[$reason['reverse_reason_key']] = $reason['reverse_reason'];
                return $allReasons;
            }, []);
        } catch (\Throwable $th) {
            set_log_extra('order', $order);
            set_log_extra('response', $data);
            throw new \Exception('Unable to retrieve rejection reasons');
        }
    }

    /**
     * Marks the items as being cancelled
     *
     * @param Order $order
     * @param Request $request
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function cancel(Order $order, Request $request)
    {
        // Cancel reason is required
        if (!$cancelReason = $request->input('cancel_reason')) {
            return $this->respondBadRequestError('You need to specify the reason.');
        }

        $data = $this->client->callRequest('POST', "reverse/order/cancel", [
            'json' => [
                'order_id' => $order->external_id,
                'cancel_reason_key' =>  $cancelReason
            ]
        ]);

        $this->get($order->external_id, ['deduct' => false]);

        return true;
    }

    /**
     * Perform cancellation action from buyer side
     *
     * @param Order $order
     * @param Request $request
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function cancellation(Order $order, Request $request)
    {
        // Action is required
        if (!$action = $request->input('action')) {
            return $this->respondBadRequestError('Accept or reject is required.');
        }

        if ($action === 'accept') {
            $data = $this->client->callRequest('POST', "reverse/reverse_request/confirm", [
                'json' => [
                    'reverse_order_id' => $order->external_id
                ]
            ]);
        } elseif ($action === 'reject') {
            if (!$rejectReason = $request->input('reject_reason')) {
                return $this->respondBadRequestError('Reject reason is required.');
            }

            $comment = $request->input('additional_comment') ?? '';

            $data = $this->client->callRequest('POST', "reverse/reverse_request/reject", [
                'json' => [
                    'reverse_order_id' => $order->external_id,
                    'reverse_reject_reason_key' => $rejectReason,
                    'reverse_reject_comments' => $comment
                ]
            ]);
        } else {
            return $this->respondBadRequestError('Invalid of cancellation action.');
        }

        $this->get($order->external_id, ['deduct' => false]);
        return true;
    }

    /**
     * Get logistic info for init
     *
     * @param Order $order
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function initInfo(Order $order)
    {
        $data = $this->client->callRequest('GET', "logistics/shipping_providers");
        return [
            'delivery_options' => $data['delivery_option_list'],
            'logistic_types' => [
                ['value' => 1, 'label' => 'Pick Up'],
                ['value' => 2, 'label' => 'Drop Off'],
            ],
            'packages' => $order->items->filter(function ($item) {
                return $item->data['package_id'];
            })->groupBy(function ($item) {
                return $item->data['package_id'];
            })
        ];
    }

    /**
     * Update order's shipping
     *
     * @param Order $order
     * @param Request $request
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function fulfillment(Order $order, Request $request)
    {
        $packages = $request->all();

        foreach ($packages as $key => $package) {
            $postData = [
                'package_id' => $package['package_id'],
                'pick_up_type' => $package['pick_up_type']
            ];

            if (isset($package['pick_up'])) {
                $postData['pick_up'] = $package['pick_up'];
            }

            if ($order->data['delivery_option'] === 'SEND_BY_SELLER') {
                if (!$package['tracking_number']) {
                    throw new \Exception('Tracking number is required for all packages');
                }

                if (!$package['shipping_provider_id']) {
                    throw new \Exception('Shipping provider is required for all packages');
                }

                $postData['self_shipment'] = [
                    'tracking_number' => $package['tracking_number'],
                    'shipping_provider_id' => $package['shipping_provider_id']
                ];
            }

            try {
                $data = $this->client->callRequest('POST', "fulfillment/rts", [
                    'json' => $postData
                ]);
            } catch (\Throwable $th) {
                throw new \Exception('Unable to fulfill order for Tiktok');
            }
        }

        sleep(3);

        $this->get($order->external_id, ['deduct' => false]);
        return true;
    }

    /**
     * Retrieve airway bill
     *
     * @param $order
     * @param Request $request
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function bill($order, Request $request)
    {
        $maximumRetryCount = 3;
        $tries = 0;
        $errorMessage = null;
        do {
            $tries++;
            try {
                $data = $this->client->callRequest('GET', "logistics/shipping_document", [
                    'query' => [
                        'order_id' => $order->external_id,
                        'document_type' => 'SL_PL'
                    ]
                ]);

                if (isset($data['doc_url']) && !empty($data['doc_url'])) {
                    sleep(1);
                    return $this->respond([
                        'file' => base64_encode(file_get_contents($data['doc_url']))
                    ]);
                }
            } catch (\Throwable $th) {
                $errorMessage = $th->getMessage();
            }
        } while ($tries < $maximumRetryCount);

        // Return error if we reach maximum retry count
        return $this->respondWithError($errorMessage ?? 'Unknown error!');
    }

    /**
     * Update the order status on the integration
     *
     * @param Shipment $shipment
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function updateOrderStatusByShipment(Shipment $shipment)
    {
        if ($shipment->integration_id === Integration::JTEXPRESS) {
            $response = true;
            switch ($shipment->status) {
                case ShipmentStatus::TRACKING_INFORMATION_RECEIVED()->getValue():
                    $order = $shipment->order;
                    if ($order->data['delivery_option'] !== 'SEND_BY_SELLER') {
                        $packages = $order->items->groupBy(function ($item) {
                            return $item->data['package_id'];
                        });

                        $packagesData = [];
                        foreach ($packages as $packageId => $package) {
                            $packagesData[] = [
                                'package_id' => $packageId,
                                'pick_up_type' => 1 // Pickup
                            ];
                        }

                        $request = new Request($packagesData);
                        $response = $this->fulfillment($order, $request);
                    } else {
                        // TODO: Need to find a way to get shipping_provider_id
                        throw new Exception('Unable to fulfill order for Tiktok');
                    }

                default:
                    break;
            }
            if (isset($response['meta']['error'])) {
                throw new Exception($response['meta']['message']);
            }
        }
    }

    public function split(Order $order, Request $request)
    {
        $packages = $request->input('packages');
        $orderItemsCount = $order->items->count();

        $selected = [];
        foreach ($packages as $package => $arr) {
            // Make sure all items is not under one same package/parcel
            if ($orderItemsCount == count($arr)) {
                return $this->respondBadRequestError('Not allow to put all item into 1 parcel, please reselect the item');
            }
            foreach ($arr as $orderItemId) {
                $selected[] = $orderItemId;
            }
        }

        $actualItems = $order->items()->whereIn('id', $selected)->get();

        if ($actualItems->count() != count($selected)) {
            return $this->respondBadRequestError('Invalid item selected');
        }

        // Make sure all items is selected
        if ($orderItemsCount != count($selected)) {
            return $this->respondBadRequestError('Make sure all the items had selected');
        }

        $splitGroups = [];
        foreach ($packages as $key => $arr) {
            $items = $order->items()->whereIn('id', $arr)->get();
            $orderLineIdList = $items->map(function ($item) {
                return $item->external_id;
            });
            $splitGroups[] = [
                'pre_split_pkg_id' => $key + 1,
                'order_line_id_list' => $orderLineIdList
            ];
        }

        $postData = [
            'order_id' => $order->external_id,
            'split_group' => $splitGroups
        ];

        $data = $this->client->callRequest('POST', "reverse/reverse_request/confirm", [
            'json' => $postData
        ]);

        if (!empty($data['fail_list'])) {
            set_log_extra('response', $data);
            set_log_extra('parameters', $postData);

            return $this->respondBadRequestError($data['fail_list'][0]['fail_reason']);
        }

        $this->get($order->external_id, ['deduct' => false]);
        return true;
    }

    /**
     * Auto update order action
     *
     * @param Order $order
     * @param null $forceAction
     * @param bool $forceTrigger
     * @param bool $autoSchedule
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function autoUpdateOrderAction(Order $order, $forceAction = null, $forceTrigger = false, $autoSchedule = false)
    {
        /** @var Account $account */
        $account = $order->account;
        $availableActions = $this->availableActions($order);

        // Tiktok auto fulfillment
        if ((is_null($forceAction) || $forceAction === 'fulfillment') && ($account->hasFeature(['auto_update_orders', 'auto_fulfillment']) || $forceTrigger) && in_array('fulfillment', $availableActions)) {

            if ($order->data['delivery_option'] !== 'SEND_BY_SELLER') {
                $packages = $order->items->groupBy(function ($item) {
                    return $item->data['package_id'];
                });

                $packagesData = [];
                foreach ($packages as $packageId => $package) {
                    $packagesData[] = [
                        'package_id' => $packageId,
                        'pick_up_type' => 1 // Pickup
                    ];
                }

                $request = new Request($packagesData);
                $response = $this->fulfillment($order, $request);
            } else {
                // TODO: Need to find a way to get shipping_provider_id
                throw new Exception('Unable to fulfill order for Tiktok');
            }
        }
    }

    /**
     * Update the order status on the integration
     *
     * @param Order $order
     * @param int $newFulfillmentStatus
     *
     * @throws \Exception
     */
    public function updateStatusFromProvider(Order $order, $newFulfillmentStatus)
    {
        if ($newFulfillmentStatus === FulfillmentStatus::READY_TO_SHIP()->getValue()) {
            $packages = $order->items->groupBy(function ($item) {
                return $item->data['package_id'];
            });

            $packagesData = [];
            foreach ($packages as $packageId => $package) {
                $packagesData[] = [
                    'package_id' => $packageId,
                    'pick_up_type' => 1 // Pickup
                ];
            }

            $request = new Request($packagesData);
            $response = $this->fulfillment($order, $request);
        }
    }
}
