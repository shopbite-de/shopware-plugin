import './page/shopbite-multi_channel_group-list';
import './page/shopbite-multi_channel_group-detail';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

Shopware.Module.register('shopbite-multi_channel_group', {
    type: 'plugin',
    name: 'MultiChannelGroup',
    title: 'shopbite-multi_channel_group.general.mainMenuItemGeneral',
    description: 'shopbite-multi_channel_group.general.descriptionText',
    color: '#4a90e2',

    snippets: {
        'en-GB': enGB,
        'de-DE': deDE
    },

    routes: {
        list: {
            component: 'shopbite-multi_channel_group-list',
            path: 'list'
        },
        detail: {
            component: 'shopbite-multi_channel_group-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'shopbite.multi_channel_group.list'
            }
        },
        create: {
            component: 'shopbite-multi_channel_group-detail',
            path: 'create',
            meta: {
                parentPath: 'shopbite.multi_channel_group.list'
            }
        }
    },

    navigation: [{
        label: 'shopbite-multi_channel_group.general.mainMenuItemGeneral',
        color: '#4a90e2',
        path: 'shopbite.multi_channel_group.list',
        parent: 'shopbite.main.index',
        position: 120
    }]
});