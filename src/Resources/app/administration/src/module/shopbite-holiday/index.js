import './page/shopbite-holiday-list';
import './page/shopbite-holiday-detail';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

Shopware.Module.register('shopbite-holiday', {
    type: 'plugin',
    name: 'Holidays',
    title: 'shopbite-holiday.general.mainMenuItemGeneral',
    description: 'shopbite-holiday.general.descriptionText',
    color: '#ff3d58',
    icon: 'regular-calendar',

    snippets: {
        'en-GB': enGB,
        'de-DE': deDE
    },

    routes: {
        list: {
            component: 'shopbite-holiday-list',
            path: 'list'
        },
        detail: {
            component: 'shopbite-holiday-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'shopbite.holiday.list'
            }
        },
        create: {
            component: 'shopbite-holiday-detail',
            path: 'create',
            meta: {
                parentPath: 'shopbite.holiday.list'
            }
        }
    },

    navigation: [{
        label: 'shopbite-holiday.general.mainMenuItemGeneral',
        color: '#ff3d58',
        path: 'shopbite.holiday.list',
        icon: 'regular-calendar',
        parent: 'shopbite.main.index',
        position: 110
    }]
});
