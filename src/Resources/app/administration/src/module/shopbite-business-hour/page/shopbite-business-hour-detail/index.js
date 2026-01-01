import template from './shopbite-business-hour-detail.html.twig';

const { Component, Mixin } = Shopware;

Component.register('shopbite-business-hour-detail', {
    template,

    inject: [
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            businessHour: null,
            salesChannels: null,
            selectedDays: [],
            isLoading: false,
            processSuccess: false
        };
    },

    computed: {
        businessHourRepository() {
            return this.repositoryFactory.create('shopbite_business_hour');
        },

        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },

        days() {
            return [
                { value: 1, label: this.$tc('shopbite-business-hour.days.monday') },
                { value: 2, label: this.$tc('shopbite-business-hour.days.tuesday') },
                { value: 3, label: this.$tc('shopbite-business-hour.days.wednesday') },
                { value: 4, label: this.$tc('shopbite-business-hour.days.thursday') },
                { value: 5, label: this.$tc('shopbite-business-hour.days.friday') },
                { value: 6, label: this.$tc('shopbite-business-hour.days.saturday') },
                { value: 7, label: this.$tc('shopbite-business-hour.days.sunday') }
            ];
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            if (this.$route.params.id) {
                this.businessHourId = this.$route.params.id;
                this.loadEntity();
            } else {
                this.businessHour = this.businessHourRepository.create();
                this.selectedDays = this.days.map(day => day.value);
                this.salesChannelRepository.search(new Shopware.Data.Criteria()).then((result) => {
                    this.salesChannels = result;
                });
            }
        },

        loadEntity() {
            this.isLoading = true;
            this.businessHourRepository.get(this.businessHourId).then((result) => {
                this.businessHour = result;
                this.selectedDays = [this.businessHour.dayOfWeek];
                this.salesChannels = new Shopware.Data.EntityCollection(
                    '/sales-channel',
                    'sales_channel',
                    Shopware.Context.api,
                    new Shopware.Data.Criteria()
                );

                if (this.businessHour.salesChannelId) {
                    this.salesChannelRepository.get(this.businessHour.salesChannelId).then((salesChannel) => {
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
                this.selectedDays.forEach((day) => {
                    const businessHour = this.businessHourRepository.create();
                    Object.assign(businessHour, this.businessHour);

                    businessHour.salesChannelId = salesChannel.id;
                    businessHour.dayOfWeek = day;

                    if (this.businessHourId) {
                        businessHour.id = this.businessHourId;
                    } else {
                        delete businessHour.id;
                    }

                    promises.push(this.businessHourRepository.save(businessHour));
                });
            });

            if (promises.length === 0) {
                this.isLoading = false;
                return;
            }

            Promise.all(promises).then(() => {
                this.isLoading = false;
                this.processSuccess = true;
                this.$router.push({ name: 'shopbite.business.hour.list' });
            }).catch((exception) => {
                this.isLoading = false;
                this.createNotificationError({
                    title: this.$tc('shopbite-business-hour.detail.errorTitle'),
                    message: exception.message
                });
            });
        }
    }
});
