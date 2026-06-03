const deDE = {
    'topdata-better-checkout-company-name-change': {
        'module': {
            'name': 'Firmennamen-Änderungen',
            'title': 'Firmennamen-Änderungsanträge',
            'description': 'Verwaltung von Änderungsanträgen für Firmennamen in Rechnungsadressen',
        },
        'list': {
            'title': 'Firmennamen-Änderungsanträge',
            'filter': {
                'status': 'Status',
            },
            'column': {
                'newCompanyName': 'Neuer Firmenname',
                'oldCompanyName': 'Alter Firmenname',
                'status': 'Status',
                'createdAt': 'Erstellt am',
            },
        },
        'detail': {
            'title': 'Firmennamen-Änderungsantrag',
            'card': {
                'requestInfo': 'Antragsdetails',
                'customerInfo': 'Kundeninformationen',
                'review': 'Prüfung',
            },
            'label': {
                'oldCompanyName': 'Alter Firmenname',
                'newCompanyName': 'Neuer Firmenname',
                'status': 'Status',
                'createdAt': 'Erstellt am',
                'reviewedAt': 'Geprüft am',
                'reviewComment': 'Kommentar',
                'customerName': 'Kundenname',
                'customerEmail': 'Kunden-E-Mail',
            },
            'placeholder': {
                'reviewComment': 'Optionaler Kommentar zur Entscheidung...',
            },
            'approve': 'Genehmigen',
            'reject': 'Ablehnen',
            'approveSuccess': 'Änderungsantrag wurde genehmigt',
            'approveError': 'Fehler beim Genehmigen des Antrags',
            'rejectSuccess': 'Änderungsantrag wurde abgelehnt',
            'rejectError': 'Fehler beim Ablehnen des Antrags',
            'notFound': 'Antrag nicht gefunden',
        },
        'status': {
            'pending': 'Ausstehend',
            'approved': 'Genehmigt',
            'rejected': 'Abgelehnt',
        },
    },
};

export default deDE;
