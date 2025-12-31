import template from './shopbite-holiday-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('shopbite-holiday-list', {
    template,

    inject: [
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('listing')
    ],

    data() {
        return {
            holidays: null,
            isLoading: true,
            sortBy: 'start',
            sortDirection: 'ASC'
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    computed: {
        holidayRepository() {
            return this.repositoryFactory.create('shopbite_holiday');
        },

        columns() {
            return [{
                property: 'start',
                label: 'shopbite-holiday.list.columnStart',
                routerLink: 'shopbite.holiday.detail',
                allowResize: true,
                primary: true
            }, {
                property: 'end',
                label: 'shopbite-holiday.list.columnEnd',
                allowResize: true
            }, {
                property: 'salesChannel.name',
                label: 'shopbite-holiday.list.columnSalesChannel',
                allowResize: true
            }];
        },

        holidayCriteria() {
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

            return this.holidayRepository.search(this.holidayCriteria).then((result) => {
                this.holidays = result;
                this.total = result.total;
                this.isLoading = false;
            });
        },

        formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleString('de-DE', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    }
});
