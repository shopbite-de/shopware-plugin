import template from './shopbite-multi_channel_group-detail.html.twig';
import './shopbite-multi_channel_group-detail.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('shopbite-multi_channel_group-detail', {
    template,

    inject: ['repositoryFactory', 'acl'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            item: null,
            isLoading: false,
            isSaveSuccessful: false
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(this.item ? this.item.name : this.$tc('shopbite-multi_channel_group.detail.titleCreate'))
        };
    },

    computed: {
        multiChannelGroupRepository() {
            return this.repositoryFactory.create('shopbite_multi_channel_group');
        },

        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },

        isEditMode() {
            return !!this.$route.params.id && this.$route.params.id !== 'create';
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            if (this.isEditMode) {
                this.loadItem();
            } else {
                this.item = this.multiChannelGroupRepository.create();
                this.item.salesChannels = new Shopware.Data.EntityCollection(
                    '/sales-channel',
                    'sales_channel',
                    Shopware.Context.api,
                    new Shopware.Data.Criteria()
                );
            }
        },

        loadItem() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addAssociation('salesChannels');

            return this.multiChannelGroupRepository
                .get(this.$route.params.id, Shopware.Context.api, criteria)
                .then((item) => {
                    this.item = item;
                    this.isLoading = false;
                })
                .catch(() => {
                    this.isLoading = false;
                    this.createNotificationError({
                        message: this.$tc('shopbite-multi_channel_group.detail.errorLoad')
                    });
                });
        },

        onChangeLanguage() {
            if (this.isEditMode) {
                this.loadItem();
            }
        },

        onSave() {
            this.isLoading = true;

            return this.multiChannelGroupRepository
                .save(this.item, Shopware.Context.api)
                .then(() => {
                    this.isLoading = false;
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        message: this.$tc('shopbite-multi_channel_group.detail.successSave')
                    });

                    this.$router.push({ name: 'shopbite.multi_channel_group.list' });
                })
                .catch((error) => {
                    this.isLoading = false;
                    console.error('Save error:', error);
                    this.createNotificationError({
                        message: this.$tc('shopbite-multi_channel_group.detail.errorSave')
                    });
                });
        },

        onCancel() {
            this.$router.push({ name: 'shopbite.multi_channel_group.list' });
        },

        getTitle() {
            if (this.isEditMode) {
                return this.$tc('shopbite-multi_channel_group.detail.titleEdit');
            }
            return this.$tc('shopbite-multi_channel_group.detail.titleCreate');
        }
    }
});