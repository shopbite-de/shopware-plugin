import './page/shopbite-main';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Module.register('shopbite-main', {
    type: 'plugin',
    name: 'ShopBite',
    title: 'ShopBite',
    color: '#ff5b00',
    icon: 'default-shopping-paper-bag-product',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },

    routes: {
        index: {
            component: 'shopbite-main-index',
            path: 'index'
        }
    },

    navigation: [{
        label: 'ShopBite',
        color: '#ff5b00',
        path: 'shopbite.main.index',
        parent: 'sw-marketing',
        position: 100
    }]
});
