<template>
    <span>
        <b-button variant="danger" class="mr-2" v-b-modal.cancel-order-modal v-if="canCancel"><i class="fas fa-times"></i> Cancel</b-button>

        <b-modal id="cancel-order-modal" ref="cancel-order-modal" size="lg"
                 header-bg-variant="danger" hide-backdrop no-close-on-backdrop no-close-on-esc no-enforce-focus>

            <template v-slot:modal-header="{ close }">
                <h2 class="mb-0 text-white">Cancel Order</h2>
                <button type="button" class="close" @click="closeCancel" aria-label="Close">
                    <span aria-hidden="true" class="text-white">×</span>
                </button>
            </template>

            <h3 class="mt-4">Select Cancel Reason</h3>
            <select name="cancel_reason" v-model="cancel_reason" class="form-control" required>
                <option :value=null selected disabled>-- Please select a cancel reason --</option>
                <option v-for="(value, key) in reasons" :value="key">{{ value }}</option>
            </select>

             <template v-slot:modal-footer="{ ok, cancel }">
                <b-button variant="link" @click="closeCancel">Close</b-button>
                <b-button variant="danger" class="ml-auto" @click="confirmCancel">Cancel Order</b-button>
            </template>
        </b-modal>
    </span>
</template>

<script>
    export default {
        name: "TiktokCancelOrderComponent",
        props: ['order'],
        data() {
            return {
                cancel_reason: null,
                reasons: [],
            }
        },
        computed: {
            canCancel() {
                if (this.order.fulfillment_status <= 10) {
                    return true
                }
                return false;
            },
        },
        methods: {
            closeCancel() {
                this.$refs['cancel-order-modal'].hide();
                this.cancel_reason = null;
            },
            retrieveReasons() {
                axios.get('/web/orders/' + this.order.id + '/tiktok/reasons').then((response) => {
                    let data = response.data;
                    if (data.meta.error) {
                        notify('top', 'Error', data.meta.message, 'center', 'danger');
                    } else {
                        this.reasons = data.response;
                    }
                }).catch((error) => {
                    if (error.response && error.response.data && error.response.data.meta) {
                        notify('top', 'Error', error.response.data.meta.message, 'center', 'danger');
                    } else {
                        notify('top', 'Error', error, 'center', 'danger');
                    }
                });
            },
            confirmCancel() {
                if (!this.cancel_reason) {
                    notify('top', 'Error', 'You need to select the reason to cancel.', 'center', 'danger');
                    return;
                }

                if (this.sending_request) {
                    return;
                }
                notify('top', 'Info', 'Updating..', 'center', 'info');
                this.sending_request = true;
                axios.post('/web/orders/' + this.order.id + '/tiktok/cancel', {
                    cancel_reason: this.cancel_reason
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
                            text: 'Successfully cancelled order! Order status might take 5 - 10 minutes to get updated.',
                            type: 'success',
                            buttonsStyling: false,
                            confirmButtonClass: 'btn btn-success'
                        }).then(() => {
                            this.closeCancel();
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
            },
        },
        created() {
            if (this.canCancel) {
                this.retrieveReasons();
            }
        },
    }
</script>

<style scoped>
    #cancel-order-modal___BV_modal_outer_ {
        z-index: 1051 !important;
    }
</style>
