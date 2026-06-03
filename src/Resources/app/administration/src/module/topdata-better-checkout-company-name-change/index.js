import defaultSearchConfiguration from './default-search-configuration';
import './service/company-name-change-request.service';
import './page/topdata-company-name-change-list';
import './page/topdata-company-name-change-detail';

const { Module } = Shopware;

Module.register('topdata-better-checkout-company-name-change', {
    type: 'plugin',
    name: 'topdata-better-checkout-company-name-change.module.name',
    title: 'topdata-better-checkout-company-name-change.module.title',
    description: 'topdata-better-checkout-company-name-change.module.description',
    color: '#ff5722',
    icon: 'regular:window',
    entity: 'topdata_better_checkout_company_name_change_request',

    routes: {
        list: {
            component: 'topdata-company-name-change-list',
            path: 'list',
        },
        detail: {
            component: 'topdata-company-name-change-detail',
            path: 'detail/:id',
        },
    },

    navigation: [{
        id: 'topdata-better-checkout-company-name-change',
        label: 'topdata-better-checkout-company-name-change.module.title',
        color: '#ff5722',
        icon: 'regular:window',
        path: 'topdata.better.checkout.company.name.change.list',
        parent: 'customer',
        position: 100,
    }],

    defaultSearchConfiguration,
});
