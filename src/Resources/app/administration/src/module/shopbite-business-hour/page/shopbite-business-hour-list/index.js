import template from './shopbite-business-hour-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('shopbite-business-hour-list', {
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
                label: 'shopbite-business-hour.list.columnDayOfWeek',
                routerLink: 'shopbite.business.hour.detail',
                inlineEdit: 'number',
                allowResize: true,
                primary: true
            }, {
                property: 'openingTime',
                label: 'shopbite-business-hour.list.columnOpeningTime',
                allowResize: true
            }, {
                property: 'closingTime',
                label: 'shopbite-business-hour.list.columnClosingTime',
                allowResize: true
            }, {
                property: 'salesChannel.name',
                label: 'shopbite-business-hour.list.columnSalesChannel',
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
                'shopbite-business-hour.days.monday',
                'shopbite-business-hour.days.tuesday',
                'shopbite-business-hour.days.wednesday',
                'shopbite-business-hour.days.thursday',
                'shopbite-business-hour.days.friday',
                'shopbite-business-hour.days.saturday',
                'shopbite-business-hour.days.sunday'
            ];

            return this.$tc(days[dayOfWeek - 1]);
        }
    }
});
