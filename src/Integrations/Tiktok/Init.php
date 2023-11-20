<?php


namespace App\Integrations\Tiktok;

use App\Constants\IntegrationType;
use App\Models\Integration;
use App\Models\Region;

class Init extends \App\Integrations\Init
{

    protected $features = [
        Region::GLOBAL => [
            'products' => [
                'import_products' => 1,
                'import_account_categories' => 1,
                'import_categories' => 1,
                'import_brands' => 1,
                'create_product' => 1,
                'create_promotion' => 1
            ],
            'orders' => [
                'import_orders' => 1,
                'sync_orders' => 1,
                'deduct_inventory' => 1,
                'return_stock' => 1,
            ],
            'inventory' => [
                'sync_inventory' => 1,
            ],
            'authentication' => [
                'enabled' => 1,
                'type' => 1,
                'readme' => "<b>Let's get started by following this guide.</b><br/>
                        Step 1 - Integrate Tiktok Account to Sellstream <br/>
                        Step 2 - Select account type and click on 'Next' <br/>
                        Step 3 - Read through the Permissions required, if agreeable, check the checkbox for service agreement and click on 'Authorize' <br/>
                        Step 4 - Enable the settings as required by operation <br/>
                        Step 5 - Import Products & Orders <br/>",
            ],
            'default_settings' => [
                'shipments' => [
                    'shipment_accounts' => [
                        'name' => 'shipment_accounts',
                        'label' => 'Shipment accounts',
                        'note' => 'Shipment account for shipping order',
                        'type' => 'accounts_multi_select',
                        'required' => 1,
                        'value' => [],
                    ],
                ],
                'account' => [
                    'warehouse' => [
                        'name' => 'warehouse',
                        'label' => 'Warehouse',
                        'note' => 'Select Tiktok warehouse and bind it to the account.',
                        'type' => 'single_select',
                        'required' => 1,
                        'data' => null,
                        'value' => null,
                    ]
                ],
            ],
        ]
    ];

    /**
     * This is the value that should be overwritten in the Init for each integration.
     *
     * The first level of array should be the region - This is to support multi region
     *
     * @var array
     */
    protected $jobs = [

        /*
         * This is an example, the key name is the method name, while the value is the cron timing
         * The method name should be available in Client
         *
         */

        Region::GLOBAL => [
            'retrieveSettlement' => '0 1 * * *',
        ]
    ];

    /**
     * Returns the integration name
     *
     * @return string
     */
    public function getName()
    {
        return 'Tiktok';
    }

    /**
     * Returns the ID
     *
     * @return integer
     */
    public function getId()
    {
        return Integration::TIKTOK;
    }

    /**
     * @return IntegrationType
     */
    function getType()
    {
        return IntegrationType::STORE();
    }
}
