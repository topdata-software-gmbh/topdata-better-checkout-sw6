import template from './topdata-company-name-change-list.html.twig';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Shopware.Component.register('topdata-company-name-change-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('listing'),
    ],

    data() {
        return {
            items: null,
            isLoading: false,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            naturalSorting: false,
            total: 0,
            filterStatus: null,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('topdata_better_checkout_company_name_change_request');
        },

        listFilters() {
            return [
                {
                    property: 'status',
                    label: this.$tc('topdata-better-checkout-company-name-change.list.filter.status'),
                    options: [
                        { value: null, label: this.$tc('global.default.all') },
                        { value: 'pending', label: this.$tc('topdata-better-checkout-company-name-change.status.pending') },
                        { value: 'approved', label: this.$tc('topdata-better-checkout-company-name-change.status.approved') },
                        { value: 'rejected', label: this.$tc('topdata-better-checkout-company-name-change.status.rejected') },
                    ],
                },
            ];
        },

        columns() {
            return [
                {
                    property: 'newCompanyName',
                    label: this.$tc('topdata-better-checkout-company-name-change.list.column.newCompanyName'),
                    allowResize: true,
                    primary: true,
                },
                {
                    property: 'oldCompanyName',
                    label: this.$tc('topdata-better-checkout-company-name-change.list.column.oldCompanyName'),
                    allowResize: true,
                },
                {
                    property: 'status',
                    label: this.$tc('topdata-better-checkout-company-name-change.list.column.status'),
                    allowResize: true,
                },
                {
                    property: 'createdAt',
                    label: this.$tc('topdata-better-checkout-company-name-change.list.column.createdAt'),
                    allowResize: true,
                },
            ];
        },
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.setTerm(this.term);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            criteria.addAssociation('customer');
            criteria.addAssociation('address');

            if (this.filterStatus) {
                criteria.addFilter(Criteria.equals('status', this.filterStatus));
            }

            return this.repository.search(criteria, Shopware.Context.api).then((result) => {
                this.items = result;
                this.total = result.total;
                this.isLoading = false;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        getStatusVariant(status) {
            const variants = {
                pending: 'warning',
                approved: 'success',
                rejected: 'danger',
            };
            return variants[status] || 'info';
        },

        getStatusLabel(status) {
            return this.$tc(`topdata-better-checkout-company-name-change.status.${status}`);
        },
    },
});
