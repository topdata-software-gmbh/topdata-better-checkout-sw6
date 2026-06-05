import template from './topdata-company-name-change-detail.html.twig';
import './topdata-company-name-change-detail.scss';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Shopware.Component.register('topdata-company-name-change-detail', {
    template,

    inject: ['repositoryFactory', 'acl', 'companyNameChangeRequestService'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            item: null,
            isLoading: false,
            isSaving: false,
            reviewComment: '',
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('tdbc_company_name_change_request');
        },

        changeRequestId() {
            return this.$route.params.id;
        },

        isPending() {
            return this.item && this.item.status === 'pending';
        },
    },

    created() {
        this.loadEntity();
    },

    methods: {
        loadEntity() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addAssociation('customer');
            criteria.addAssociation('address');

            this.repository.get(this.changeRequestId, Shopware.Context.api, criteria).then((result) => {
                this.item = result;
                this.isLoading = false;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        onApprove() {
            this.isSaving = true;

            this.companyNameChangeRequestService.approve(this.item.id, this.reviewComment).then(() => {
                this.createNotificationSuccess({
                    message: this.$tc('topdata-better-checkout-company-name-change.detail.approveSuccess'),
                });
                this.loadEntity();
                this.isSaving = false;
            }).catch(() => {
                this.createNotificationError({
                    message: this.$tc('topdata-better-checkout-company-name-change.detail.approveError'),
                });
                this.isSaving = false;
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

        onReject() {
            this.isSaving = true;

            this.companyNameChangeRequestService.reject(this.item.id, this.reviewComment).then(() => {
                this.createNotificationSuccess({
                    message: this.$tc('topdata-better-checkout-company-name-change.detail.rejectSuccess'),
                });
                this.loadEntity();
                this.isSaving = false;
            }).catch(() => {
                this.createNotificationError({
                    message: this.$tc('topdata-better-checkout-company-name-change.detail.rejectError'),
                });
                this.isSaving = false;
            });
        },
    },
});
