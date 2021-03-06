<script type="text/ecmascript-6">
export default {
    props: ['resourceName', 'resourceId', 'field'],

    data() {
        return {
            loading: true,
            user: null,
            plans: null,
            subscriptions: null,
            plan: null
        }
    },


    computed: {
        basePath() {
            return Nova.config.base;
        }
    },


    mounted() {
        this.loadUserData();
    },


    methods: {
        loadUserData() {
            Nova.request().get(`/nova-cashier-tool-api/user/${this.resourceId}/subscriptions`)
                .then(response => {
                    this.user = response.data.user;
                    this.plans = response.data.plans;
                    this.subscriptions = response.data.subscriptions;

                    this.loading = false;
                });
        },
        createSubscription() {
            this.loading = true;

            if (this.plan) {
                Nova.request().post('/nova-cashier-tool-api/user/' + this.resourceId + '/subscriptions/create', {plan: this.plan})
                    .then(response => {
                        this.$toasted.show("Created successfully!", {type: "success"});

                        this.loadUserData();
                    })
                    .catch(errors => {
                        this.$toasted.show(errors.response.data.message, {type: "error"});
                        this.loading = false;
                    });
            } else {
                this.$toasted.show("Please choose a plan.", {type: "error"});
                this.loading = false;
            }
        },
    }
}
</script>

<template>
    <div>
        <div class="flex items-center mb-3">
            <h1 class="flex-no-shrink text-90 font-normal text-2xl">Manage Subscription</h1>
        </div>
        <div class="card mb-6 py-3 px-6">
            <loading-view :loading="loading">
                <div v-if="!subscriptions || subscriptions.length == 0"
                     class="flex border-b border-40 remove-bottom-border">
                    <p class="text-90">
                        <em>User has no subscriptions.</em>
                        <br/>
                    </p>
                </div>
                <div v-else>
                    <div v-for="subscription in subscriptions" class="subscription-div">
                        <div class="flex border-b border-40" v-if="subscription">
                            <div class="w-1/4 py-4"><h4 class="font-normal text-80">Plan</h4></div>
                            <div class="w-3/4 py-4"><p class="text-90">
                                {{ subscription.plan_nickname }}
                                ({{ subscription.plan_amount / 100 }} {{ subscription.plan_currency }} /
                                {{ subscription.plan_interval_count }}
                                {{ subscription.plan_interval }})
                            </p></div>
                        </div>

                        <div class="flex border-b border-40" v-if="subscription">
                            <div class="w-1/4 py-4"><h4 class="font-normal text-80">Subscribed since</h4></div>
                            <div class="w-3/4 py-4"><p class="text-90">{{ subscription.created_at }}</p></div>
                        </div>

                        <div class="flex border-b border-40" v-if="subscription">
                            <div class="w-1/4 py-4"><h4 class="font-normal text-80">Billing Period</h4></div>
                            <div class="w-3/4 py-4"><p class="text-90">{{ subscription.current_period_start }} =>
                                {{ subscription.current_period_end }}</p></div>
                        </div>

                        <div class="flex border-b border-40 remove-bottom-border" v-if="subscription">
                            <div class="w-1/4 py-4"><h4 class="font-normal text-80">Status</h4></div>
                            <div class="w-3/4 py-4">
                                <p class="text-90">
                                    <span v-if="subscription.on_grace_period">On Grace Period</span>
                                    <span v-if="subscription.cancelled || subscription.cancel_at_period_end"
                                          class="text-danger">Cancelled</span>
                                    <span
                                        v-if="subscription.active && !subscription.cancelled && !subscription.cancel_at_period_end">Active</span>
                                    ·
                                    <a class="text-primary no-underline"
                                       :href="basePath+'/cashier-tool/user/'+resourceId+'/subscriptions/'+subscription.id">
                                        Manage
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </loading-view>
        </div>
    </div>
</template>

<style lang="scss">
.subscription-div {
    h3 {
        margin-top: 10px;
    }
}
</style>
