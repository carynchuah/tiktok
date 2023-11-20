<?php

namespace App\Integrations\Tiktok;

use App\Constants\CategoryAttributeLevel;
use App\Constants\CategoryAttributeType;
use App\Constants\Dimension;
use App\Constants\EntityType;
use App\Constants\MarketplaceProductStatus;
use App\Constants\ProductIdentifier;
use App\Constants\ProductPriceType;
use App\Constants\ProductStatus;
use App\Constants\ShippingType;
use App\Constants\Weight;
use App\Integrations\AbstractProductAdapter;
use App\Integrations\TransformedProduct;
use App\Integrations\TransformedProductImage;
use App\Integrations\TransformedProductListing;
use App\Integrations\TransformedProductPrice;
use App\Integrations\TransformedProductVariant;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationCategory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImportTask;
use App\Models\ProductListing;
use GuzzleHttp\RequestOptions;

class ProductAdapter extends AbstractProductAdapter
{
    /**
     * Retrieves a single product
     *
     * @param ProductListing $listing
     * @param bool $update Whether or not to update the product if it already exists
     * @param null $itemId
     * @return mixed
     * @throws \Exception
     */
    public function get($listing, $update = false, $itemId = null, $config = [])
    {
        $externalId = null;
        if ($itemId) {
            $externalId = $itemId;
        } elseif ($listing) {
            /*
            * Check whether it have main product external id
            * If there is same sku, then it might have chance getting the wrong external id
            * So will check the main product external id first
            **/
            $externalId = $listing->getIdentifier(ProductIdentifier::PRODUCT_ID());

            if (empty($externalId)) {
                // Need to make sure is main product listing
                if (!empty($listing->listing) && !is_null($listing->listing)) {
                    $listing = $listing->listing;
                }

                $externalId = $listing->identifiers['external_id'];
            }
        }

        try {
            $data = $this->client->callRequest('GET', "products/details", [
                'query' => [
                    'product_id' => $externalId
                ]
            ]);

            $product = $this->transformProduct($data);
            if (empty($product)) {
                return null;
            }

            return $this->handleProduct($product, array_merge($config, ['update' => $update, 'new' => $update]));
        } catch (\Throwable $th) {
            set_log_extra('identifiers', $externalId);
            throw new \Exception('Unable to retrieve product for Tiktok');
        }
    }

    /**
     * Import all products from tiktok
     *
     * @param ProductImportTask|null $importTask
     * @param array $config
     * @return boolean
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function import(?ProductImportTask $importTask, array $config)
    {
        $filters = [];
        $products = $this->fetchAllProducts($filters);

        if (is_object($products) && !empty($products)) {
            if (!empty($importTask) && empty($importTask->total_products)) {
                $importTask->total_products = $products->count();
                $importTask->save();
            }

            foreach ($products as $product) {
                if (!empty($product)) {
                    try {
                        $product = $this->get(null, true, $product['id'], $config);
                    } catch (\Throwable $th) {
                        set_log_extra('unable to import product', $product);
                        throw new \Exception('Products import for Tiktok failed');
                    }
                }
            }
        }

        if ($config['delete']) {
            $this->removeDeletedProducts();
        }

        return true;
    }

    /**
     * Get all products from Tiktok
     *
     * @param array $filters
     * @return \Illuminate\Support\Collection
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function fetchAllProducts($filters = [])
    {
        $allProducts = [];
        $page = 1;
        $offset = 0;
        $limit = 100;
        do {
            [$products, $totalProducts] = $this->fetchProducts($filters, $page, $limit);
            $allProducts = array_merge($allProducts, $products);

            $page++;
            $offset += count($products);
        } while (!empty($totalProducts) && $offset < $totalProducts && !empty($products));

        return collect($allProducts);
    }

    /**
     * Get products for each page
     *
     * @param array $filters
     * @param int $offset
     * @param int $limit
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function fetchProducts($filters = [], $page = 1, $limit = 50)
    {
        $data = $this->client->callRequest('POST', "products/search", [
            'json' => array_merge($filters, [
                'page_number' => $page,
                'page_size' => $limit,
            ])
        ]);

        $products = isset($data['products']) ? $data['products'] : [];
        $total = isset($data['total']) ? $data['total'] : null;

        return [$products, $total];
    }

    /**
     * Syncs the product listing to ensure the stock is correct, deleted products are removed and also for
     * the product status to be accurate
     *
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function sync()
    {
        // No point syncing as there's no listings under this account yet.
        if ($this->account->listings()->count() === 0) {
            return;
        }

        $products = $this->fetchAllProducts();

        if (!empty($products)) {
            foreach ($products as $product) {
                if (!empty($product)) {
                    try {
                        $product = $this->get(null, true, $product['id']);
                    } catch (\Exception $e) {
                        set_log_extra('product', $product);
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Pushes the update for the ProductListing
     *
     * @param ProductListing $product
     * @param array $data
     * @return mixed
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function update(ProductListing $product, array $data)
    {
        $productData = [];
        $productData['product_id'] = $data['identifiers']['external_id'] ?? $product->identifiers['external_id'];
        $productData['product_name'] = $data['name'];
        $productData['description'] = $data['html_description'];

        $productData['category_id'] = $data['category']['external_id'] ?? $product->integration_category->external_id;

        $warehouseId = $this->account->getSetting(['account', 'warehouse']);
        if (empty($warehouseId)) {
            $defaultWarehouse = $this->client->getDefaultWarehouse();
            $warehouseId = $defaultWarehouse['warehouse_id'] ?? null;
        }

        $firstVariant = isset($data['variants'][0]) ? $data['variants'][0] : null;

        $productData['package_length'] = $firstVariant ? $firstVariant->length : 0;
        $productData['package_width'] = $firstVariant ? $firstVariant->width : 0;
        $productData['package_height'] = $firstVariant ? $firstVariant->height : 0;

        $productData['package_weight'] = $firstVariant ? $firstVariant->weight : 0;

        if (!empty($firstVariant) && !empty($firstVariant->weight)) {
            $productData['package_weight'] = convert_weight($firstVariant->weight, Weight::from($firstVariant['weight_unit']), Weight::KILOGRAMS());
        }

        $attributes = $data['attributes'];
        $categoryAttributes = $product->integration_category->attributes;

        $productAttributes = [];
        foreach ($categoryAttributes as $key => $categoryAttribute) {
            if (isset($attributes[$categoryAttribute->name])) {
                $values = [];
                if ($categoryAttribute->type === CategoryAttributeType::TEXT()->getValue()) {
                    $values[] = [
                        'value_name' => $attributes[$categoryAttribute->name]->value
                    ];
                } elseif ($categoryAttribute->type === CategoryAttributeType::MULTI_SELECT()->getValue()) {
                    $selectedValues = json_decode($attributes[$categoryAttribute->name]->value, true);
                    foreach ($selectedValues as $selectedValue) {
                        $values[] = [
                            'value_id' => $selectedValue['id']
                        ];
                    }
                } elseif ($categoryAttribute->type === CategoryAttributeType::SINGLE_SELECT()->getValue()) {
                    $options = $categoryAttribute->data;
                    $selectedValue = collect($options)->firstWhere('name', $attributes[$categoryAttribute->name]->value);
                    if (!empty($selectedValue)) {
                        $values[] = [
                            'value_id' => $selectedValue['id']
                        ];
                    }
                }

                $productAttributes[] = [
                    'attribute_id' => $categoryAttribute->name,
                    'attribute_values' => $values
                ];
            }
        }
        $productData['product_attributes'] = $productAttributes;

        $skus = [];
        foreach ($data['variants'] as $key => $variant) {
            $skuImg = null;
            $variantImage =  $this->uploadImage($variant, [
                'image_url' => $variant['main_image']
            ]);
            if (!empty($variantImage) && isset($variantImage['img_id'])) {
                $skuImg = [
                    'id' => $variantImage['img_id']
                ];
            }

            $salesAttribute = [];
            if (!empty($skuImg)) {
                $salesAttribute[] = [
                    'sku_img' => $skuImg
                ];
            }

            $stockInfos = [];
            if (!empty($warehouseId)) {
                $stockInfos = [
                    [
                        'warehouse_id' => $warehouseId,
                        'available_stock' => $variant['stock']
                    ]
                ];
            }

            $skus[] = [
                'sales_attribute' => $salesAttribute,
                'stock_info' => $stockInfos,
                'seller_sku' => $variant['sku'],
                'original_price' => $variant['price'],
                'product_identifier_code' => [
                    'identifier_code' => $variant['barcode'],
                    'identifier_code_type' => 4 // ISBN
                ],
                'product_attributes' => []
            ];
        }

        $productData['skus'] = $skus;

        $productData['is_cod_open'] = true;

        try {
            $data = $this->client->callRequest('PUT', "fulfillment/products", [
                'json' => $productData
            ]);
        } catch (\Throwable $th) {
            set_log_extra('data', $productData);
            throw new \Exception('Failed to update product in Tiktok');
        }

        sleep(1);
        $this->get($product, true);

        return $this->respond($data);
    }

    /**
     * Upload image to product
     *
     * @param Product $product
     * @param array $image
     *
     * @return id | null
     */
    private function uploadImage($product, $image)
    {
        if (isset($image['data_url'])) {
            $src = upload_image($image['data_url'], session('shop'));
        } else {
            $src =  $image['image_url'];
        }

        $data = $this->client->callRequest('POST', "products/upload_imgs", [
            'json' => [
                'img_data' => base64_encode(file_get_contents($src)),
                'img_scene' => 1 // PRODUCT_IMAGE
            ]
        ]);

        if (isset($data['img_id'])) {
            if ($image instanceof ProductImage) {
                $image->update([
                    'external_id' => $data['img_id'],
                    'integration_id' => $this->account->integration->id,
                    'height' => $data['img_height'],
                    'width' => $data['img_width'],
                    'image_url' => $data['img_url']
                ]);
            }

            return $data;
        }

        return null;
    }

    /**
     * Creates a new product on the account from the product model
     *
     * @param \App\Models\Product $product
     * @return mixed
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function create(Product $product)
    {
        // pre-load required relation data
        $this->preLoadProductData($product);

        // Get main product attributes
        $attributes = $product->attributes->where('product_variant_id', null)->mapWithKeys(function ($item) {
            return [$item['name'] => $item];
        });

        $warehouseId = $this->account->getSetting(['account', 'warehouse']);
        if (empty($warehouseId)) {
            $defaultWarehouse = $this->client->getDefaultWarehouse();
            $warehouseId = $defaultWarehouse['warehouse_id'] ?? null;
        }

        if (isset($attributes['integration_category_id'])) {
            /** @var IntegrationCategory $integrationCategory */
            $integrationCategory = IntegrationCategory::where([
                'id' => $attributes['integration_category_id']['value'],
                'integration_id' => Integration::TIKTOK
            ])->first();
        } else {
            /** @var IntegrationCategory $integrationCategory */
            $integrationCategory = $product->category->integrationCategories->first();
        }

        $categoryAttributes = $integrationCategory->attributes;

        $productAttributes = [];
        foreach ($categoryAttributes as $key => $categoryAttribute) {
            if (isset($attributes[$categoryAttribute->name])) {
                $values = [];
                if ($categoryAttribute->type === CategoryAttributeType::TEXT()->getValue()) {
                    $values[] = [
                        'value_name' => $attributes[$categoryAttribute->name]->value
                    ];
                } elseif ($categoryAttribute->type === CategoryAttributeType::MULTI_SELECT()->getValue()) {
                    $selectedValues = json_decode($attributes[$categoryAttribute->name]->value, true);
                    foreach ($selectedValues as $selectedValue) {
                        $values[] = [
                            'value_id' => $selectedValue['id']
                        ];
                    }
                } elseif ($categoryAttribute->type === CategoryAttributeType::SINGLE_SELECT()->getValue()) {
                    $options = $categoryAttribute->data;
                    $selectedValue = collect($options)->firstWhere('name', $attributes[$categoryAttribute->name]->value);
                    if (!empty($selectedValue)) {
                        $values[] = [
                            'value_id' => $selectedValue['id']
                        ];
                    }
                }

                $productAttributes[] = [
                    'attribute_id' => $categoryAttribute->name,
                    'attribute_values' => $values
                ];
            }
        }

        $productData = [];
        $productData['product_name'] = $product->name;
        $productData['description'] = $product->html_description;
        $productData['category_id'] = $integrationCategory->external_id;

        $productImages = [];
        foreach ($product->allImages as $key => $image) {
            $uploadedImage =  $this->uploadImage($product, $image);
            if (!empty($uploadedImage) && isset($uploadedImage['img_id'])) {
                $productImages[] = $uploadedImage['img_id'];
            }
        }
        $productData['images'] = $productImages;

        $firstVariant = isset($product['variants'][0]) ? $product['variants'][0] : null;

        $productData['package_length'] = $firstVariant ? $firstVariant->length : 0;
        $productData['package_width'] = $firstVariant ? $firstVariant->width : 0;
        $productData['package_height'] = $firstVariant ? $firstVariant->height : 0;

        $productData['package_weight'] = $firstVariant ? $firstVariant->weight : 0;
        if (!empty($firstVariant) && !empty($firstVariant->weight) && !empty($firstVariant['weight_unit'])) {
            $productData['package_weight'] = convert_weight($firstVariant->weight, Weight::from($firstVariant['weight_unit']), Weight::KILOGRAMS());
        }

        $productData['product_attributes'] = $productAttributes;

        $skus = [];
        foreach ($product->variants as $key => $variant) {
            $skuImg = null;
            $variantImage =  $this->uploadImage($variant, [
                'image_url' => $variant['main_image']
            ]);
            if (!empty($variantImage) && isset($variantImage['img_id'])) {
                $skuImg = [
                    'id' => $variantImage['img_id']
                ];
            }

            $salesAttribute = [];
            if (!empty($skuImg)) {
                $salesAttribute[] = [
                    'sku_img' => $skuImg
                ];
            }

            $stockInfos = [];
            if (!empty($warehouseId)) {
                $stockInfos = [
                    [
                        'warehouse_id' => $warehouseId,
                        'available_stock' => $variant['stock']
                    ]
                ];
            }

            $skus[] = [
                'sales_attribute' => $salesAttribute,
                'stock_info' => $stockInfos,
                'seller_sku' => $variant['sku'],
                'original_price' => $variant['price'],
                'product_identifier_code' => [
                    'identifier_code' => $variant['barcode'],
                    'identifier_code_type' => 4 // ISBN
                ],
                'product_attributes' => []
            ];
        }

        $productData['skus'] = $skus;

        $productData['is_cod_open'] = true;

        try {
            $data = $this->client->callRequest('POST', "products", [
                'json' => $productData
            ]);

            $productId = $data['product_id'];
        } catch (\Throwable $th) {
            set_log_extra('data', $productData);
            throw new \Exception('Failed to create product in Tiktok');
        }

        sleep(1);
        $product = $this->get(null, true, $productId);
        return $this->respondCreated($product);
    }

    /**
     * Update variant images
     *
     * @param $product
     * @param $imageIndexes
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateVariantImage($product, $imageIndexes)
    {
        $variants = $product['product']['variants'];
        $images = $product['product']['images'];

        foreach ($imageIndexes as $imageIndex) {
            if (!is_null($imageIndex['imageIndex'])) {
                $variantId = $variants[$imageIndex['variantIndex']]['id'] ?? null;
                $imageId = $images[$imageIndex['imageIndex']]['id'] ?? null;

                if ($variantId && $imageId) {
                    $data['variant'] = [
                        'id' => $variantId,
                        'image_id' => $imageId
                    ];

                    $response = $this->client->callRequest('PUT',  '/admin/api/2020-07/variants/'.$variantId.'.json', [RequestOptions::JSON => $data]);
                    /*if ($response->getStatusCode() === 200) {

                    } else {
                        return $this->respondWithError($response->getBody()->getContents());
                    }*/
                }
            }
        }
        return true;
    }

    /**
     * Deletes the product from the integration
     *
     * @param ProductListing $listing
     * @return bool
     * @throws \Exception
     */
    public function delete(ProductListing $listing)
    {
        // Get main product listing
        if (!empty($listing->listing) && !is_null($listing->listing)) {
            $listing = $listing->listing;
        }

        $externalId = $listing->getIdentifier(ProductIdentifier::EXTERNAL_ID());

        if (empty($externalId)) {
            set_log_extra('listing', $listing);
            throw new \Exception('Tiktok product does not have product external id');
        }

        try {
            $data = $this->client->callRequest("DELETE", "products", [
                'json' => [
                    'product_ids' => [
                        $externalId
                    ]
                ]
            ]);
        } catch (\Throwable $th) {
            set_log_extra('listing', $listing);
            throw new \Exception('Unable to delete product in Tiktok.');
        }

        return true;
    }

    /**
     * Retrieves all the categories for the IntegrationCategory
     *
     */
    public function retrieveCategories()
    {
        $data = $this->client->callRequest('GET', 'products/categories');

        if (isset($data['category_list'])) {
            $allCategories = collect($data['category_list']);
            $parentCategories = $allCategories->filter(function($category) {
                return $category['parent_id'] === '0';
            });

            $parsedCategories = [];
            foreach ($parentCategories as $key => $category) {
                $name = $category['local_display_name'];
                $children = $allCategories->filter(function($childCategory) use($category) {
                    return $childCategory['parent_id'] === $category['id'];
                });

                $parsedCategories[] = [
                    'name'          => $name,
                    'breadcrumb'    => $name,
                    'external_id'   => $category['id'],
                    'is_leaf'       => $category['is_leaf'] ? 1 : 0,
                    'children'      => !empty($children) ? $this->parseCategories($children, $name, $allCategories) : []
                ];
            }
            return $parsedCategories;
        } else {
            set_log_extra('response', $data);
            throw new \Exception('Unable to retrieve categories for Lazada');
        }
    }

    /**
     * Recursive function to get all children of the category
     *
     * @param $children
     *
     * @param $parentName
     *
     * @return array
     */
    private function parseCategories($children, $parentName, $allCategories) {
        $result = [];
        if (!empty($children)) {
            foreach ($children as $child) {
                $name = $child['local_display_name'];
                $children = $allCategories->filter(function($grandChild) use($child) {
                    return $grandChild['parent_id'] === $child['id'];
                });

                $breadcrumb = $parentName . ' > ' . $name;
                $result[] = [
                    'name'  => $name,
                    'breadcrumb'  => $breadcrumb,
                    'external_id' => $child['id'],
                    'is_leaf'  => $child['is_leaf'] ? 1 : 0,
                    'children' => !empty($children) ? $this->parseCategories($children, $breadcrumb, $allCategories) : [],
                ];
            }
        }
        return $result;
    }

    /**
     * Retrieves all the attributes for the IntegrationCategory
     *
     * @param IntegrationCategory $category
     * @throws \Exception
     */
    public function retrieveCategoryAttribute(IntegrationCategory $category)
    {
        try {
            $data = $this->client->callRequest('GET', "products/attributes", [
                'query' => [
                    'category_id' => $category->external_id
                ]
            ]);

            $attributes = $data['attributes'];
            foreach ($attributes as $key => $attribute) {
                $attributeType = CategoryAttributeType::TEXT();
                if (isset($attribute['values'])) {
                    if ($attribute['input_type']['is_multiple_selected']) {
                        $attributeType = CategoryAttributeType::MULTI_SELECT();
                    } else {
                        $attributeType = CategoryAttributeType::SINGLE_SELECT();
                    }
                }

                $attributes[$key] = [
                    'label' => $attribute['name'],
                    'name' => $attribute['id'],
                    'required' => $attribute['input_type']['is_mandatory'],
                    'type' => $attributeType,
                    'level' => CategoryAttributeLevel::GENERAL(),
                    'data' => $attribute['values'] ?? [],
                    'additional_data' => ['is_sale_prop' => $attribute['attribute_type'] === 2]
                ];
            }

            return $attributes;
        } catch (\Throwable $th) {
            set_log_extra('category', $category);
            throw new \Exception('Unable to get category attributes from Tiktok.');
        }
    }

    /**
     * @param $product
     *
     * @return TransformedProduct
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function transformProduct($product)
    {
        // Associated SKU can only be set later from the first variant as Tiktok does not have a "parent" SKU
        $associatedSku = null;
        // Status can only be retrieved from the first variant
        $status = null;

        $mainProductIdentifier = [ProductIdentifier::EXTERNAL_ID()->getValue() => $product['product_id']];
        $shortDescription = null;
        $htmlDescription = $product['description'] ?? null;
        $name = $product['product_name'] ?? null;

        $brand = null;
        if (isset($product['brand']) && isset($product['brand']['id'])) {
            if ($brandModel = Brand::find($product['brand']['id'])) {
                $brand = $brandModel;
            }
        }

        $model = null;

        $options = [];

        $leafCategory = collect($product['category_list'])->firstWhere('is_leaf', true);

        $integrationCategory = null;
        if (!empty($leafCategory)) {
            /** @var IntegrationCategory $integrationCategory */
            $integrationCategory = IntegrationCategory::where([
                'integration_id' => $this->account->integration_id,
                'region_id' => $this->account->region_id,
                'external_id' => $leafCategory['id'],
            ])->first();
        }

        // Tiktok doesn't support account category
        $accountCategory = null;

        $category = null;

        $productImages = [];
        // save product''s image if exist, if empty, use variant first image as product image
        foreach ($product['images'] as $index => $productImage) {
            if (!empty($productImage['url_list'])) {
                $productImages[] = new TransformedProductImage($productImage['url_list'][0], $productImage['id'], $productImage['width'], $productImage['height'], $index);
            }
        }

        $mpStatus = $product['product_status'];
        if ($mpStatus === 1) {
            $status = ProductStatus::DRAFT();
            $marketplaceStatus = MarketplaceProductStatus::PENDING();
        } elseif ($mpStatus === 2) {
            $status = ProductStatus::DRAFT();
            $marketplaceStatus = MarketplaceProductStatus::PENDING();
        } elseif ($mpStatus === 3) {
            $status = ProductStatus::DRAFT();
            $marketplaceStatus = MarketplaceProductStatus::HAS_ISSUES();
        }  elseif ($mpStatus === 4) {
            $status = ProductStatus::LIVE();
            $marketplaceStatus = MarketplaceProductStatus::LIVE();
        } elseif ($mpStatus === 5 || $mpStatus === 6 || $mpStatus === 7) {
            $status = ProductStatus::DISABLED();
            $marketplaceStatus = MarketplaceProductStatus::DISABLED();
        } elseif ($mpStatus === 6) {
            $status = ProductStatus::DISABLED();
            $marketplaceStatus = MarketplaceProductStatus::BANNED();
        } elseif ($mpStatus === 8) {
            return null;
        } else {
            set_log_extra('status', $mpStatus);
            throw new \Exception('Invalid Tiktok product status.');
        }

        $attributes = [];
        if (isset($product['product_attributes'])) {
            $attributes = collect($product['product_attributes'])->reduce(function ($allAttributes, $currentAttribute) {
                if (isset($currentAttribute['value_name'])) {
                    $allAttributes[$currentAttribute['name']] = $currentAttribute['value_name'];
                } elseif ($currentAttribute['values']) {
                    $allAttributes[$currentAttribute['name']] = collect($currentAttribute['values'])->map(function ($value) {
                        return $value['name'];
                    })->join(', ');
                }

                return $allAttributes;
            }, []);
        }

        // Looping through and creating all the variants
        $variants = [];

        if (!isset($product['skus']) || empty($product['skus'])) {
            // Add a default variant if does not exists (data based on main product)

            // Tiktok doesn't have a product URL for the listing, only for each SKU
            $productUrl = null;

            // No prices for main product
            $prices = null;

            $option1 = null;
            $option2 = null;
            $option3 = null;

            $weightUnit = Weight::KILOGRAMS();
            $weight = $product['package_weight'] ?? 0; // single variant don't have weight

            $shippingType = ShippingType::MARKETPLACE();
            $dimensionUnit = Dimension::CM();
            $length = $product['package_length'] ?? 0; // single variant don't have dimension
            $width = $product['package_width'] ?? 0;
            $height = $product['package_height'] ?? 0;

            $barcode = null;
            $stock = null;

            $variantListing = new TransformedProductListing($name, $mainProductIdentifier, $integrationCategory,
                $accountCategory, $prices, $productUrl, $stock, $attributes, $associatedSku, $productImages, $marketplaceStatus, null, $option1, $option2, $option3);

            $variants[] = new TransformedProductVariant($name, $option1, $option2, $option3, $associatedSku, $barcode, $stock, $prices, null, $status, $shippingType, $weight, $weightUnit, $length, $width, $height, $dimensionUnit, $variantListing, null);
        }

        foreach ($product['skus'] as $sku) {

            // Tiktok does not support names for the SKU, so we should implode from the option values, or use the default name
            $variantName = $name.' ~ '.$sku['seller_sku'];

            // We pull the first variation as the associated_sku
            if (empty($associatedSku)) {
                $associatedSku = $sku['seller_sku'];
            }
            $variantSku = $sku['seller_sku'];

            // Tiktok doesn't support barcodes
            $barcode = null;

            $storeWarehouseId = $this->account->getSetting(['account', 'warehouse']);
            if (empty($storeWarehouseId)) {
                $defaultWarehouse = $this->client->getDefaultWarehouse();
                $storeWarehouseId = $defaultWarehouse['warehouse_id'] ?? null;
            }

            if (empty($storeWarehouseId)) {
                $stock = collect($sku['stock_infos'])->reduce(function ($quantity, $info) {
                    return $quantity + $info['available_stock'];
                }, 0);
            } else {
                $warehouseStock = collect($sku['stock_infos'])->firstWhere('warehouse_id', $storeWarehouseId);
                $stock = empty($warehouseStock) ? 0 : $warehouseStock['available_stock'];
            }

            $prices = [];
            // Normal price
            $prices[] = new TransformedProductPrice($this->account->currency, $sku['price']['original_price'], ProductPriceType::SELLING());

            $images = [];
            foreach ($sku['sales_attributes'] as $index => $salesAttribute) {
                if (isset($salesAttribute['sku_img']) && !empty($salesAttribute['sku_img']['url_list'])) {
                    $images[] = new TransformedProductImage($salesAttribute['sku_img']['url_list'][0], $salesAttribute['sku_img']['id'], $salesAttribute['sku_img']['width'], $salesAttribute['sku_img']['height'], $index);
                }
            }

            $weightUnit = Weight::KILOGRAMS();
            $weight = $sku['package_weight'] ?? 0; // single variant don't have weight

            $shippingType = ShippingType::MARKETPLACE();
            $dimensionUnit = Dimension::CM();
            $length = $sku['package_length'] ?? 0; // single variant don't have dimension
            $width = $sku['package_width'] ?? 0;
            $height = $sku['package_height'] ?? 0;

            $productUrl = null;


            $identifiers = [
                ProductIdentifier::EXTERNAL_ID()->getValue() => $sku['id'],
                ProductIdentifier::SKU()->getValue() => $variantSku
            ];

            $option1 = null;
            $option2 = null;
            $option3 = null;

            $variantAttributes = [];
            if (isset($sku['sales_attributes'])) {
                $variantAttributes = collect($sku['sales_attributes'])->reduce(function ($allAttributes, $currentAttribute) {
                    if (isset($currentAttribute['value_name'])) {
                        $allAttributes[$currentAttribute['name']] = $currentAttribute['value_name'];
                    } elseif ($currentAttribute['values']) {
                        $allAttributes[$currentAttribute['name']] = collect($currentAttribute['values'])->map(function ($value) {
                            return $value['name'];
                        })->join(', ');
                    }
                    return $allAttributes;
                }, []);
            }

            $variantListing = new TransformedProductListing($variantName, $identifiers, $integrationCategory,
                $accountCategory, $prices, $productUrl, $stock, $variantAttributes, $sku, $images, $marketplaceStatus, null, $option1, $option2, $option3);

            $variants[] = new TransformedProductVariant($variantName, $option1, $option2, $option3, $variantSku, $barcode, $stock, $prices, null, $status, $shippingType, $weight, $weightUnit, $length, $width, $height, $dimensionUnit, $variantListing, null);
        }

        // Tiktok doesn't have a product URL for the listing, only for each SKU
        $productUrl = null;

        // No prices for main product
        $prices = null;

        // Setting the status for the main product to live because not sure what else to set here, unless we calculate
        // based on the statuses above to see if there's any that's live, or we use the last value
        $listing = new TransformedProductListing($name, $mainProductIdentifier, $integrationCategory, $accountCategory, $prices, $productUrl, null, $attributes, $product, $productImages, $marketplaceStatus, $options);

        $product = new TransformedProduct($name, $associatedSku, $shortDescription, $htmlDescription, $brand, $model, $category, $status, $variants, $options, $listing, $productImages);

        return $product;
    }

    /**
     * Pushes the update for the stock in ProductListing.
     * NOTE: This should force an update of the listing after updating (Not updated locally prior to actual push)
     *
     * @param ProductListing $product
     * @param $stock
     * @throws \Exception
     */
    public function updateStock(ProductListing $product, $stock)
    {
        if (empty($product->product_variant_id)) {
            throw new \Exception('Tiktok does not support updating main stock.');
        }

        if (empty($product->listing)) {
            throw new \Exception('Variant does not have a main listing.');
        }

        $productId = $product->listing->getIdentifier(ProductIdentifier::EXTERNAL_ID());
        $variantId = $product->getIdentifier(ProductIdentifier::EXTERNAL_ID());

        $stockInfo = [
            'available_stock' => $stock
        ];

        $storeWarehouseId = $this->account->getSetting(['account', 'warehouse']);
        if (empty($storeWarehouseId)) {
            $defaultWarehouse = $this->client->getDefaultWarehouse();
            $storeWarehouseId = $defaultWarehouse['warehouse_id'] ?? null;
        }

        if (!empty($storeWarehouseId)) {
            $stockInfo['warehouse_id'] = $storeWarehouseId;
        }

        try {
            $data = $this->client->callRequest('PUT', "products/stocks", [
                'json' => [
                    'product_id' => $productId,
                    'skus' => [
                        [
                            'id' => $variantId,
                            'stock_infos' => [
                                $stockInfo
                            ]
                        ]
                    ]
                ]
            ]);

            $this->get(null, false, $productId);
        } catch (\Throwable $th) {
            set_log_extra('listing', $product);
            throw new \Exception('Unable to update stock in Tiktok.');
        }
    }

    /**
     * Change listing variant status enable/disable
     *
     * @param ProductListing $listing
     * @param bool $enabled
     * @return bool
     * @throws \Exception
     */
    public function toggleEnable(ProductListing $listing, $enabled = true)
    {
        if (!empty($listing->listing) && !is_null($listing->listing)) {
            $listing = $listing->listing;
        }

        $externalId = $listing->getIdentifier(ProductIdentifier::EXTERNAL_ID());

        if (empty($externalId)) {
            set_log_extra('listing', $listing);
            throw new \Exception('Tiktok product does not have product external id');
        }

        try {
            $url = $enabled ? 'products/activate' : 'products/inactivated_products';

            $data = $this->client->callRequest('POST', $url, [
                'json' => [
                    'product_ids' => [
                        $externalId
                    ]
                ]
            ]);

            sleep(1);

            $this->get($listing, true);
        } catch (\Throwable $th) {
            set_log_extra('listing', $listing);
            throw new \Exception('Unable to update status in Tiktok.');
        }

        return true;
    }

    /**
     * Retrieves all the brands for the integration
     *
     * @return array
     * @throws \Exception
     */
    public function retrieveBrands()
    {
        $offset = 0;
        $limit = 100;
        $allBrands = [];
        $page = 1;
        $total = null;

        do {
            try {
                $data = $this->client->callRequest('GET', "products/brands", [
                    'query' => [
                        'page_size' => $limit,
                        'page_number' => $page
                    ]
                ]);

                $brands = isset($data['brand_list']) ? $data['brand_list'] : [];
                $allBrands = array_merge($allBrands, $brands);

                $total = isset($data['total_num']) ? $data['total_num'] : null;
                $page++;
            } catch(\Exception $e) {
                set_log_extra('response', $e);
                throw new \Exception('Tiktok-'.$this->account->id.' Unable to connect and retrieve brands.');
            }

            $offset += count($brands);
        } while (!empty($total) && $offset < $total && !empty($brands));

        $brands = [];
        foreach ($allBrands as $key => $brand) {
            $brands[$key] = [
                'name'          => $brand['name'],
                'external_id'   => $brand['id'],
            ];
        }

        return $brands;
    }
}
