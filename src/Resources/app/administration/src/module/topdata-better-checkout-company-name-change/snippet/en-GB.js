const enGB = {
    'topdata-better-checkout-company-name-change': {
        'module': {
            'name': 'Company Name Changes',
            'title': 'Company Name Change Requests',
            'description': 'Manage company name change requests for billing addresses',
        },
        'list': {
            'title': 'Company Name Change Requests',
            'filter': {
                'status': 'Status',
            },
            'column': {
                'newCompanyName': 'New Company Name',
                'oldCompanyName': 'Old Company Name',
                'status': 'Status',
                'createdAt': 'Created At',
            },
        },
        'detail': {
            'title': 'Company Name Change Request',
            'card': {
                'requestInfo': 'Request Details',
                'customerInfo': 'Customer Information',
                'review': 'Review',
            },
            'label': {
                'oldCompanyName': 'Old Company Name',
                'newCompanyName': 'New Company Name',
                'status': 'Status',
                'createdAt': 'Created At',
                'reviewedAt': 'Reviewed At',
                'reviewComment': 'Comment',
                'customerName': 'Customer Name',
                'customerEmail': 'Customer Email',
            },
            'placeholder': {
                'reviewComment': 'Optional comment for the decision...',
            },
            'approve': 'Approve',
            'reject': 'Reject',
            'approveSuccess': 'Change request has been approved',
            'approveError': 'Error approving the request',
            'rejectSuccess': 'Change request has been rejected',
            'rejectError': 'Error rejecting the request',
            'notFound': 'Request not found',
        },
        'status': {
            'pending': 'Pending',
            'approved': 'Approved',
            'rejected': 'Rejected',
        },
    },
};

export default enGB;
