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
            isLoading: false,
            processSuccess: false
        };
    },

    computed: {
        businessHourRepository() {
            return this.repositoryFactory.create('shopbite_business_hour');
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
            }
        },

        loadEntity() {
            this.isLoading = true;
            this.businessHourRepository.get(this.businessHourId).then((result) => {
                this.businessHour = result;
                this.isLoading = false;
            });
        },

        onSave() {
            this.isLoading = true;

            this.businessHourRepository.save(this.businessHour).then(() => {
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
