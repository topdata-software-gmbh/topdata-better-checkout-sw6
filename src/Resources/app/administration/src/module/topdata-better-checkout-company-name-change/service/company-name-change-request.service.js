const { Application } = Shopware;

Application.addServiceProvider('companyNameChangeRequestService', () => {
    const httpClient = Shopware.Application.getContainer('init').httpClient;

    return {
        approve(id, reviewComment = null) {
            return httpClient.post(
                `topdata-better-checkout/company-name-change-request/${id}/approve`,
                { reviewComment },
                {
                    headers: {
                        'Content-Type': 'application/json',
                    },
                }
            );
        },

        reject(id, reviewComment = null) {
            return httpClient.post(
                `topdata-better-checkout/company-name-change-request/${id}/reject`,
                { reviewComment },
                {
                    headers: {
                        'Content-Type': 'application/json',
                    },
                }
            );
        },
    };
});
