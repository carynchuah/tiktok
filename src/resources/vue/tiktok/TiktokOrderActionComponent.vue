<template>
    <div>
        <div class="row">
            <div class="col-12">
                <template>
                    <b-button variant="warning" class="mr-2" @click="buyerCancellation('reject')" v-if="canBuyerCancellation"><i class="fas fa-ban"></i> Reject Cancellation</b-button>
                    <b-button variant="success" class="mr-2" @click="buyerCancellation('accept')" v-if="canBuyerCancellation"><i class="fas fa-check"></i> Accept Cancellation</b-button>
                    <b-button variant="info" class="mr-2" @click="bill" v-if="canBill"><i class="fas fa-file-invoice"></i> Airway Bill</b-button>
                    <tiktok-split-order-component :order="this.order"></tiktok-split-order-component>
                    <tiktok-fullfill-order-component :order="this.order"></tiktok-fullfill-order-component>
                </template>
                <tiktok-cancel-order-componenet :order="this.order"></tiktok-cancel-order-componenet>
            </div>
            <div class="col-12" v-if="order.fulfillment_status >= fulfillmentStatus.DELIVERED">
                There are currently no actions you can take for this order.
            </div>
        </div>
    </div>
</template>

<script>
    import TiktokCancelOrderComponenet from "./orders/TiktokCancelOrderComponent";
    import TiktokFullfillOrderComponent from "./orders/TiktokFullfillOrderComponent";
    import TiktokSplitOrderComponent from "./orders/TiktokSplitOrderComponent";
    import { FulfillmentStatus } from "./../../../../../../../resources/js/composables/Config";
    export default {
        name: "TiktokOrderActionComponent",
        components: {TiktokSplitOrderComponent, TiktokFullfillOrderComponent, TiktokCancelOrderComponenet},
        props: ['order'],
        data () {
            return {
                /*cancel_order: [],
                close_order_modal: false,*/
                sending_request: false,
            }
        },
        setup() {
            const fulfillmentStatus = FulfillmentStatus;

            return { fulfillmentStatus }
        },
        computed: {
            canBuyerCancellation() {
                if (this.order.fulfillment_status === this.fulfillmentStatus.REQUEST_CANCEL) {
                    return true;
                }
                return false;
            },
            canBill() {
                let count = 0;
                this.order.items.forEach(item => {
                    if (item.fulfillment_status === this.fulfillmentStatus.READY_TO_SHIP || item.fulfillment_status === this.fulfillmentStatus.SHIPPED) {
                        count++;
                    }
                });
                return count > 0;
            }
        },
        methods: {
            bill() {
                if (this.sending_request) {
                    return;
                }
                notify('top', 'Info', 'Updating..', 'center', 'info');
                this.sending_request = true;
                axios.post('/web/orders/' + this.order.id + '/tiktok/bill', {}).then((response) => {
                    let data = response.data;
                    if (data.meta.error) {
                        notify('top', 'Error', data.meta.message, 'center', 'danger');
                    } else {
                        if (data.response.file) {
                            // https://stackoverflow.com/questions/2805330/opening-pdf-string-in-new-window-with-javascript
                            // If Browser is Edge
                            if (window.navigator && window.navigator.msSaveOrOpenBlob) {
                                let byteCharacters = window.atob(data.response.file);
                                let byteNumbers = new Array(byteCharacters.length);
                                for (let i = 0; i < byteCharacters.length; i++) {
                                    byteNumbers[i] = byteCharacters.charCodeAt(i);
                                }
                                let byteArray = new Uint8Array(byteNumbers);
                                let blob = new Blob([byteArray], {
                                    type: 'application/pdf'
                                });
                                window.navigator.msSaveOrOpenBlob(blob, "tiktok airway bill.pdf");
                            } else {
                                let pdfWindow = window.open("", '_blank');
                                pdfWindow.document.write("<iframe width='100%' style='margin: -8px;border: none;' height='100%' src='data:application/pdf;base64, " + encodeURI(data.response.file) + "'></iframe>");
                            }
                        } else {
                            notify('top', 'Error', 'Unable to get bill file', 'center', 'danger');
                        }
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
            buyerCancellation(action) {
                let title = null;
                let text = null;
                if (action === 'reject') {
                    title = 'Are you sure to reject the cancellation?';
                    text = 'Confirm to reject?';
                } else if (action === 'accept') {
                    title = 'Are you sure to accept the cancellation?';
                    text = 'Confirm to accept?';
                } else {
                    notify('top', 'Error', 'Invalid of cancellation action, please try again', 'center', 'danger');
                    return;
                }

                swal.fire({
                    title: title,
                    text: text,
                    showCancelButton: true,
                    type: 'warning',
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Confirm!'
                }).then((result) => {
                    if (result.value) {
                        // Accept or reject the cancellation
                        if (this.sending_request) {
                            return;
                        }
                        notify('top', 'Info', 'Updating..', 'center', 'info');
                        this.sending_request = true;
                        axios.post('/web/orders/' + this.order.id + '/tiktok/cancellation', {
                            action: action,
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
                                    text: 'Successfully cancelled order!',
                                    type: 'success',
                                    buttonsStyling: false,
                                    confirmButtonClass: 'btn btn-success'
                                }).then(() => {
                                    this.$parent.$parent.$parent.updateCurrent();
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
                    }
                })
            },
        }
    }
</script>

<style scoped>

</style>
