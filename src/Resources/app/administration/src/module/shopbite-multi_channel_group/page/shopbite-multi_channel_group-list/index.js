import template from './shopbite-multi_channel_group-list.html.twig';
import './shopbite-multi_channel_group-list.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('shopbite-multi_channel_group-list', {
    template,

    inject: [
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('listing')
    ],

    data() {
        return {
            multiChannelGroups: null,
            isLoading: true,
            sortBy: 'name',
            sortDirection: 'ASC'
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    computed: {
        multiChannelGroupRepository() {
            return this.repositoryFactory.create('shopbite_multi_channel_group');
        },

        columns() {
            return [{
                property: 'name',
                label: 'shopbite-multi_channel_group.list.columnName',
                routerLink: 'shopbite.multi_channel_group.detail',
                allowResize: true,
                primary: true
            }, {
                property: 'salesChannels',
                label: 'shopbite-multi_channel_group.list.columnSalesChannels',
                allowResize: true
            }, {
                property: 'createdAt',
                label: 'shopbite-multi_channel_group.list.columnCreatedAt',
                allowResize: true
            }];
        },

        multiChannelGroupCriteria() {
            const criteria = new Criteria(this.page, this.limit);

            criteria.setTerm(this.term);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            criteria.addAssociation('salesChannels');

            return criteria;
        }
    },

    methods: {
        getList() {
            this.isLoading = true;

            return this.multiChannelGroupRepository.search(this.multiChannelGroupCriteria).then((result) => {
                this.multiChannelGroups = result;
                this.total = result.total;
                this.isLoading = false;
            });
        },

        getSalesChannelNames(salesChannels) {
            if (!salesChannels || salesChannels.length === 0) {
                return '';
            }

            return salesChannels
                .map(channel => channel.name || channel.translated?.name)
                .filter(name => !!name)
                .join(', ');
        }
    }
});