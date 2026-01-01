import template from './shopbite-holiday-detail.html.twig';

const { Component, Mixin } = Shopware;

Component.register('shopbite-holiday-detail', {
    template,

    inject: [
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            holiday: null,
            salesChannels: null,
            isLoading: false,
            processSuccess: false
        };
    },

    computed: {
        holidayRepository() {
            return this.repositoryFactory.create('shopbite_holiday');
        },

        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            if (this.$route.params.id) {
                this.holidayId = this.$route.params.id;
                this.loadEntity();
            } else {
                this.holiday = this.holidayRepository.create();
                this.salesChannelRepository.search(new Shopware.Data.Criteria()).then((result) => {
                    this.salesChannels = result;
                });
            }
        },

        loadEntity() {
            this.isLoading = true;
            this.holidayRepository.get(this.holidayId).then((result) => {
                this.holiday = result;
                this.salesChannels = new Shopware.Data.EntityCollection(
                    '/sales-channel',
                    'sales_channel',
                    Shopware.Context.api,
                    new Shopware.Data.Criteria()
                );

                if (this.holiday.salesChannelId) {
                    this.salesChannelRepository.get(this.holiday.salesChannelId).then((salesChannel) => {
                        this.salesChannels.add(salesChannel);
                    });
                }

                this.isLoading = false;
            });
        },

        onSave() {
            this.isLoading = true;

            const promises = [];

            this.salesChannels.forEach((salesChannel) => {
                const holiday = this.holidayRepository.create();
                Object.assign(holiday, this.holiday);

                holiday.salesChannelId = salesChannel.id;

                if (this.holidayId) {
                    holiday.id = this.holidayId;
                } else {
                    delete holiday.id;
                }

                promises.push(this.holidayRepository.save(holiday));
            });

            if (promises.length === 0) {
                this.isLoading = false;
                return;
            }

            Promise.all(promises).then(() => {
                this.isLoading = false;
                this.processSuccess = true;
                this.$router.push({ name: 'shopbite.holiday.list' });
            }).catch((exception) => {
                this.isLoading = false;
                this.createNotificationError({
                    title: this.$tc('shopbite-holiday.detail.errorTitle'),
                    message: exception.message
                });
            });
        }
    }
});
