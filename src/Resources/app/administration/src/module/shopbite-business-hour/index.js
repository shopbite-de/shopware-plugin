import './page/shopbite-business-hour-list';
import './page/shopbite-business-hour-detail';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

Shopware.Module.register('shopbite-business-hour', {
    type: 'plugin',
    name: 'Business Hours',
    title: 'shopbite-business-hour.general.mainMenuItemGeneral',
    description: 'shopbite-business-hour.general.descriptionText',
    color: '#ff3d58',
    icon: 'regular-clock',

    snippets: {
        'en-GB': enGB,
        'de-DE': deDE
    },

    routes: {
        list: {
            component: 'shopbite-business-hour-list',
            path: 'list'
        },
        detail: {
            component: 'shopbite-business-hour-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'shopbite.business.hour.list'
            }
        },
        create: {
            component: 'shopbite-business-hour-detail',
            path: 'create',
            meta: {
                parentPath: 'shopbite.business.hour.list'
            }
        }
    },

    navigation: [{
        label: 'shopbite-business-hour.general.mainMenuItemGeneral',
        color: '#ff3d58',
        path: 'shopbite.business.hour.list',
        icon: 'regular-clock',
        parent: 'shopbite.main.index',
        position: 100
    }]
});
