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
            isLoading: false,
            processSuccess: false
        };
    },

    computed: {
        holidayRepository() {
            return this.repositoryFactory.create('shopbite_holiday');
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
            }
        },

        loadEntity() {
            this.isLoading = true;
            this.holidayRepository.get(this.holidayId).then((result) => {
                this.holiday = result;
                this.isLoading = false;
            });
        },

        onSave() {
            this.isLoading = true;

            this.holidayRepository.save(this.holiday).then(() => {
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
