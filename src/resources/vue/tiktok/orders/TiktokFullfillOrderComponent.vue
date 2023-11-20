<template>
    <span>
        <b-button variant="primary" class="mr-2" @click="initInfo()" v-if="canFulfill"><i class="fas fa-shipping-fast"></i> Fulfillment</b-button>

        <b-modal id="order-logistic-modal" :ref="'order-logistic-modal-' + this.order.id" size="lg"
                 header-bg-variant="primary" hide-backdrop no-close-on-backdrop no-close-on-esc no-enforce-focus>

            <template v-slot:modal-header="{ close }">
                <h2 class="mb-0 text-white">Update Shipment</h2>
                <button type="button" class="close" @click="closeFulfill" aria-label="Close">
                    <span aria-hidden="true" class="text-white">×</span>
                </button>
            </template>

            <!-- Logistic Types -->
            <div class="accordion" role="tablist">
                <b-card v-for="(data, packageId) in shipmentData" :key="packageId" no-body class="mb-1">
                    <b-card-header header-tag="header" class="p-1" role="tab">
                        <b-button block v-b-toggle.accordion-1 variant="primary">Package #{{ packageId }}</b-button>
                    </b-card-header>
                    <b-collapse id="accordion-1" visible accordion="my-accordion" role="tabpanel">
                        <b-card-body>
                            <template v-if="logistic_types">
                                <b-form-select v-model="data.pick_up_type">
                                    <!-- This slot appears above the options from 'options' prop -->
                                    <template v-slot:first>
                                        <b-form-select-option :value="null" disabled>-- Logistic Type --</b-form-select-option>
                                    </template>
                                    <option v-for="logistic_type in logistic_types" :value="logistic_type.value">
                                        {{ logistic_type.label }}
                                    </option>
                                </b-form-select>
                            </template>

                            <template v-if="data.pick_up_type">
                                <template>
                                    <h3 class="mt-4">Shipping Pickup From</h3>
                                    <input-field-component
                                        :id="`${packageId}_pickup_from_datetime`"
                                        :type="'datetime'"
                                        placeholder="Pickup From"
                                        :model.sync="data.pickup_from_datetime"
                                    >
                                    </input-field-component>

                                    <h3 class="mt-4">Shipping Pickup To</h3>
                                    <input-field-component
                                        :id="`${packageId}_pickup_to_datetime`"
                                        :type="'datetime'"
                                        placeholder="Pickup To"
                                        :model.sync="data.pickup_to_datetime"
                                    >
                                    </input-field-component>

                                    <template v-if="sendBySeller">
                                        <h3 class="mt-4">Delivery Option</h3>
                                        <b-form-select v-model="data.delivery_option_id">
                                            <template v-slot:first>
                                                <b-form-select-option :value="null" disabled>-- Delivery Option --</b-form-select-option>
                                            </template>
                                            <option v-for="option in delivery_options" :value="option.delivery_option_id">
                                                {{ option.delivery_option_name }}
                                            </option>
                                        </b-form-select>

                                        <template v-if="shippingProvidersByDeliveryOption[data.delivery_option_id]">
                                            <h3 class="mt-4">Shipping Provider</h3>
                                            <b-form-select v-model="data.shipping_provider_id">
                                                <template v-slot:first>
                                                    <b-form-select-option :value="null" disabled>-- Shipping Provider --</b-form-select-option>
                                                </template>
                                                <option v-for="provider in shippingProvidersByDeliveryOption[data.delivery_option_id]" :value="provider.shipping_provider_id">
                                                    {{ provider.shipping_provider_name }}
                                                </option>
                                            </b-form-select>
                                        </template>

                                        <h3 class="mt-4">Tracking No</h3>
                                        <b-form-input v-model="data.tracking_number" placeholder="Enter tracking number"></b-form-input>
                                    </template>
                                </template>
                            </template>
                        </b-card-body>
                    </b-collapse>
                </b-card>
            </div>

            <template v-slot:modal-footer="{ ok, cancel }">
                <b-button variant="link" @click="closeFulfill">Cancel</b-button>
                <b-button variant="primary" class="ml-auto" @click="confirmFulfill">Update Shipping</b-button>
            </template>
        </b-modal>
    </span>
</template>

<script>
    import {keyBy, mapValues} from 'lodash'
import moment from 'moment';

    export default {
        name: "TiktokFullfillOrderComponent",
        props: ['order'],
        data () {
            return {
                logistic_types: [],
                packages: [],
                shipmentData: [],
                delivery_options: [],
            }
        },
        computed: {
            sendBySeller() {
                return this.order.data['delivery_option'] === 'SEND_BY_SELLER'
            },
            canFulfill() {
                return this.order.items.some(
                    item => !!item.data.package_id && (
                        item.fulfillment_status === 2 && (!this.sendBySeller || !item.tracking_number)
                    ) || item.fulfillment_status === 13
                )
            },
            shippingProvidersByDeliveryOption() {
                return mapValues(keyBy(this.delivery_options, 'delivery_option_id'), 'shipping_provider_list')
            }
        },
        methods: {
            closeFulfill() {
                this.$refs['order-logistic-modal-' + this.order.id].hide();
                this.delivery_options = []
                this.packages = []
                this.shipmentData = []
            },
            initInfo() {
                if (this.sending_request) {
                    return;
                }
                this.sending_request = true;
                axios.post('/web/orders/' + this.order.id + '/tiktok/initInfo', {}).then((response) => {
                    let data = response.data;
                    if (data.meta.error) {
                        notify('top', 'Error', data.meta.message, 'center', 'danger');
                    } else {
                        if (data.response.packages.length < 1) {
                            this.sending_request = false;
                            notify('top', 'Error', 'No item is ready for fulfillment', 'center', 'danger');

                            return
                        }

                        this.delivery_options = data.response.delivery_options;
                        this.logistic_types = data.response.logistic_types;
                        this.packages = data.response.packages;

                        this.shipmentData = Object.keys(this.packages).reduce((allData, shipmentId) => {
                            allData[shipmentId] = {
                                pick_up_type: null,
                                tracking_number: null,
                                pickup_from_datetime: null,
                                pickup_to_datetime: null,
                                delivery_option_id: null,
                                shipping_provider_id: null
                            }

                            return allData
                        }, {})

                        this.$refs['order-logistic-modal-' + this.order.id].show();
                    }
                    this.sending_request = false;
                }).catch((error) => {
                    if (error.response && error.response.data && error.response.data.meta) {
                        notify('top', 'Error', error.response.data.meta.message, 'center', 'danger');
                    } else {
                        notify('top', 'Error', error, 'center', 'danger');
                    }
                    this.sending_request = false;
                });
            },
            confirmFulfill() {
                if (this.sending_request) {
                    return;
                }
                notify('top', 'Info', 'Updating..', 'center', 'info');
                this.sending_request = true;

                let parameters = []
                Object.keys(this.shipmentData).forEach(packageId => {
                    let data = this.shipmentData[packageId];
                    data.package_id = packageId

                    if (data.pick_up_type !== 1 && data.pick_up_type !== 2) {
                        notify('top', 'Error', 'Invalid type of shipping', 'center', 'danger');
                        this.sending_request = false;
                        return;
                    }

                    if (this.sendBySeller) {
                        if (!data.shipping_provider_id) {
                            notify('top', 'Error', 'Shipping provider is required for this order', 'center', 'danger');
                            this.sending_request = false;
                            return;
                        }

                        if (!data.tracking_number) {
                            notify('top', 'Error', 'Tracking number is required for this order', 'center', 'danger');
                            this.sending_request = false;
                            return;
                        }
                    }

                    let pick_up_start_time = null
                    let pick_up_end_time = null

                    if (data.pickup_from_datetime) {
                        pick_up_start_time = moment(data.pickup_from_datetime).unix()
                        delete data.pickup_from_datetime
                    }

                    if (data.pickup_to_datetime) {
                        pick_up_end_time = moment(data.pickup_to_datetime).unix()
                        delete data.pickup_to_datetime
                    }

                    if (pick_up_start_time && pick_up_end_time) {
                        data.pick_up = {
                            pick_up_start_time,
                            pick_up_end_time
                        }
                    }

                    parameters.push(data)
                });

                axios.post('/web/orders/' + this.order.id + '/tiktok/fulfillment', parameters).then((response) => {
                    let data = response.data;
                    if (data.meta.error) {
                        swal({
                            title: 'Error',
                            text: data.meta.message,
                            type: 'error',
                            buttonsStyling: false,
                            confirmButtonClass: 'btn btn-info'
                        })
                        notify('top', 'Error', data.meta.message, 'center', 'danger');
                    } else {
                        swal({
                            title: 'Success',
                            text: 'Successfully fulfilled order, please wait approx 5 minutes to update the order status!',
                            type: 'success',
                            buttonsStyling: false,
                            confirmButtonClass: 'btn btn-success'
                        }).then(() => {
                            this.closeFulfill();
                            // this.$parent.$parent.$parent.updateCurrent();
                            window.location.reload(); // temporary fix
                        })
                    }
                    this.sending_request = false;
                }).catch((error) => {
                    if (typeof error.response != 'undefined' && typeof error.response.data != 'undefined' && typeof error.response.data.debug != 'undefined') {
                        notify('top', 'Error', error.response.data.debug[0].message, 'center', 'danger');
                    } else if (typeof error.meta != 'undefined' && typeof error.meta.message != 'undefined') {
                        notify('top', 'Error', error.meta.message, 'center', 'danger');
                    } else if (error.response && error.response.data && error.response.data.meta) {
                        notify('top', 'Error', error.response.data.meta.message, 'center', 'danger');
                    } else {
                        notify('top', 'Error', error, 'center', 'danger');
                    }
                    this.sending_request = false;
                });
            }
        }
    }
</script>

<style scoped>
    #order-logistic-modal___BV_modal_outer_ {
        z-index: 1051 !important;
    }
</style>
