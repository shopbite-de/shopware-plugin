import template from './shopbite-business_hour-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('shopbite-business_hour-list', {
    template,

    inject: [
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('listing')
    ],

    data() {
        return {
            businessHours: null,
            isLoading: true,
            sortBy: 'dayOfWeek',
            sortDirection: 'ASC'
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    computed: {
        businessHourRepository() {
            return this.repositoryFactory.create('shopbite_business_hour');
        },

        columns() {
            return [{
                property: 'dayOfWeek',
                label: 'shopbite-business_hour.list.columnDayOfWeek',
                routerLink: 'shopbite.business.hour.detail',
                inlineEdit: 'number',
                allowResize: true,
                primary: true
            }, {
                property: 'openingTime',
                label: 'shopbite-business_hour.list.columnOpeningTime',
                allowResize: true
            }, {
                property: 'closingTime',
                label: 'shopbite-business_hour.list.columnClosingTime',
                allowResize: true
            }, {
                property: 'salesChannel.name',
                label: 'shopbite-business_hour.list.columnSalesChannel',
                allowResize: true
            }];
        },

        businessHourCriteria() {
            const criteria = new Criteria(this.page, this.limit);

            criteria.setTerm(this.term);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            criteria.addAssociation('salesChannel');

            return criteria;
        }
    },

    methods: {
        getList() {
            this.isLoading = true;

            return this.businessHourRepository.search(this.businessHourCriteria).then((result) => {
                this.businessHours = result;
                this.total = result.total;
                this.isLoading = false;
            });
        },

        getDayName(dayOfWeek) {
            const days = [
                'shopbite-business_hour.days.monday',
                'shopbite-business_hour.days.tuesday',
                'shopbite-business_hour.days.wednesday',
                'shopbite-business_hour.days.thursday',
                'shopbite-business_hour.days.friday',
                'shopbite-business_hour.days.saturday',
                'shopbite-business_hour.days.sunday'
            ];

            return this.$tc(days[dayOfWeek - 1]);
        }
    }
});
