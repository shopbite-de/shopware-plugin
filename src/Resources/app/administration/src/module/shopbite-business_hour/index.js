import './page/shopbite-business_hour-list';
import './page/shopbite-business_hour-detail';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

Shopware.Module.register('shopbite-business_hour', {
    type: 'plugin',
    name: 'Business Hours',
    title: 'shopbite-business_hour.general.mainMenuItemGeneral',
    description: 'shopbite-business_hour.general.descriptionText',
    color: '#ff3d58',
    icon: 'regular-clock',

    snippets: {
        'en-GB': enGB,
        'de-DE': deDE
    },

    routes: {
        list: {
            component: 'shopbite-business_hour-list',
            path: 'list'
        },
        detail: {
            component: 'shopbite-business_hour-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'shopbite.business_hour.list'
            }
        },
        create: {
            component: 'shopbite-business_hour-detail',
            path: 'create',
            meta: {
                parentPath: 'shopbite.business_hour.list'
            }
        }
    },

    navigation: [{
        label: 'shopbite-business_hour.general.mainMenuItemGeneral',
        color: '#ff3d58',
        path: 'shopbite.business_hour.list',
        icon: 'regular-clock',
        parent: 'shopbite.main.index',
        position: 100
    }]
});
