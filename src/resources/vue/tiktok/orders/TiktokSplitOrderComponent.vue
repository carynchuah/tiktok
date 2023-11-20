<template>
    <span>
        <b-button variant="info" class="mr-2" v-b-modal.split-order-modal v-if="canSplit"><i class="fas fa-columns"></i> Split</b-button>

        <b-modal id="split-order-modal" ref="split-order-modal" size="lg"
                 header-bg-variant="primary" hide-backdrop no-close-on-backdrop no-close-on-esc no-enforce-focus>

            <template v-slot:modal-header="{ close }">
                <h2 class="mb-0 text-white">Split Order</h2>
                <button type="button" class="close" @click="closeSplit" aria-label="Close">
                    <span aria-hidden="true" class="text-white">×</span>
                </button>
            </template>

            <template v-for="(n,index) in packages_count">
                <div class="border border-success py-4 text-center mb-4 cursor-pointer" @click="openItems(index)">
                    <template v-if="!form[index] || form[index].length <= 0">
                        <span>+ Please click here to select item(s)</span>
                    </template>
                    <template v-else>
                        <div class="p-1">
                            <table class="table align-items-center table-flush mb-0">
                                <caption></caption>
                                <thead class="thead-light">
                                <tr>
                                    <th>Name</th>
                                    <th class="text-center">Quantity</th>
                                </tr>
                                </thead>
                                <tbody class="list">
                                    <tr v-for="item in items" v-if="form[index] && form[index].includes(item.id)">
                                        <td>
                                            <template v-if="item.product">
                                                <a :href="'/dashboard/products/' + item.product.slug | prependDomain" target="_blank">{{ item.name }}</a>
                                            </template>
                                            <template v-else>
                                                {{ item.name }}
                                            </template><br />
                                            <span v-if="item.variation_name">{{ item.variation_name }}<br /></span>
                                            <span v-if="item.sku">SKU: {{ item.sku }}</span>
                                        </td>
                                        <td class="text-center"><p>{{ item.quantity }}</p></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>
            </template>
            <b-button variant="info" class="mr-2 mt-2" @click="addPackage()"><i class="fas fa-plus"></i> Add Package</b-button>

            <template v-slot:modal-footer="{ ok, cancel }">
                <b-button variant="link" @click="closeSplit">Close</b-button>
                <b-button variant="danger" class="ml-auto" @click="confirmSpilt">Split Order</b-button>
            </template>
        </b-modal>

        <b-modal id="item-order-modal" ref="item-order-modal"
                 header-bg-variant="info" hide-backdrop no-close-on-backdrop no-close-on-esc no-enforce-focus>

            <template v-slot:modal-header="{ close }">
                <h2 class="mb-0 text-white">Select Item For Package {{ package_index + 1}}</h2>
                <button type="button" class="close" @click="closeItems" aria-label="Close">
                    <span aria-hidden="true" class="text-white">×</span>
                </button>
            </template>

            <h3>Select Items</h3>
            <table class="table align-items-center table-flush mb-0" :key="key.table">
                <caption></caption>
                <thead class="thead-light">
                <tr>
                    <th style="width: 60px;"></th>
                    <th>Name</th>
                    <th class="text-center">Quantity</th>
                </tr>
                </thead>
                <tbody class="list">
                    <tr v-for="item in items" :class="'cursor-pointer ' + (form[package_index] && form[package_index].includes(item.id) ? 'table-success' : '') + (item.row_disable ? 'table-danger' : '')" @click="selectItem(item)" >
                        <td style="width: 60px;">
                            <template v-if="!item.row_disable">
                                <div class="custom-control custom-checkbox">
                                    <input class="custom-control-input" :id="'item-id-' + item.id" type="checkbox" :checked="form[package_index] && form[package_index].includes(item.id)" @click="selectItem(item)">
                                    <label class="custom-control-label" :for="'item-id-' + item.id"></label>
                                </div>
                            </template>
                        </td>
                        <td>
                            <template v-if="item.product">
                                <a :href="'/dashboard/products/' + item.product.slug | prependDomain" target="_blank">{{ item.name }}</a>
                            </template>
                            <template v-else>
                                {{ item.name }}
                            </template><br />
                            <span v-if="item.variation_name">{{ item.variation_name }}<br /></span>
                            <span v-if="item.sku">SKU: {{ item.sku }}</span>
                        </td>
                        <td class="text-center"><p>{{ item.quantity }}</p></td>
                    </tr>
                </tbody>
            </table>
        </b-modal>

    </span>
</template>

<script>
    export default {
        name: "TiktokSplitOrderComponent",
        props: ['order'],
        data() {
            return {
                packages_count: 2,
                package_index: null,
                fields: [{key: 'check', label: ''}, 'name', 'quantity'],
                form: [],
                items: [],
                key: {
                    table: 'table-0',
                },
                sending_request: false
            }
        },
        computed: {
            canSplit() {
                return this.order.items.length >= 2 &&
                    this.order.data['can_split'] &&
                    this.order.items.some(({ fulfillment_status }) => fulfillment_status === 2)
            },
        },
        methods: {
            closeSplit() {
                this.items = [];
                this.form = [];
                this.package_index = null;
                this.packages_count = 2;
                this.$refs['split-order-modal'].hide();
            },
            closeItems() {
                this.$refs['item-order-modal'].hide();
            },
            addPackage() {
                if (this.packages_count < this.order.items.length) {
                    this.packages_count++;
                } else {
                    notify('top', 'Error', 'Package cannot more than items', 'center', 'danger');
                }
            },
            openItems(package_index) {
                this.package_index = package_index;
                this.items = this.order.items.map(item => {
                    return {
                        ...item,
                        row_disable: this.disabledItem(package_index, item)
                    };
                });
                this.key.table += 1;
                this.$refs['item-order-modal'].show();
            },
            selectItem(item) {
                if (item.row_disable) {
                    return;
                }
                if (!this.form[this.package_index]) {
                    this.form[this.package_index] = [];
                }
                if (this.form[this.package_index].includes(item.id)) {
                    let index = this.form[this.package_index].indexOf(item.id);
                    this.form[this.package_index].splice(index, 1);
                } else {
                    this.form[this.package_index].push(item.id);
                }
                this.key.table += 1;
            },
            disabledItem(package_index, item) {
                let count = 0;
                // If item id is at other index then disable it
                this.form.forEach((el, index) => {
                    if (index !== package_index) {
                        el.forEach((e) => {
                            if (e == item.id) {
                                count++;
                            }
                        });
                    }
                });
                return count > 0;
            },
            confirmSpilt() {
                let count = 0;
                // Make sure all order items had selected
                this.form.forEach((el, index) => {
                    el.forEach((e) => {
                        count++;
                    });
                });

                if (count !== this.order.items.length) {
                    notify('top', 'Error', 'Make sure all the order items had selected to packages.', 'center', 'danger');
                    return;
                }
                if (this.sending_request) {
                    return;
                }
                notify('top', 'Info', 'Updating...', 'center', 'info');
                this.sending_request = true;
                axios.post('/web/orders/' + this.order.id + '/tiktok/split', {
                    packages: this.form
                }).then((response) => {
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
                            text: 'Successfully split order!',
                            type: 'success',
                            buttonsStyling: false,
                            confirmButtonClass: 'btn btn-success'
                        }).then(() => {
                            this.closeSplit();
                            this.$parent.$parent.updateCurrent();
                        })
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
        }
    }
</script>

<style scoped>

</style>
