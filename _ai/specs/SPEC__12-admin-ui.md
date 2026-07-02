# Administration UI Modifications

## Company Name Change Admin Module

Vue.js module located in `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/`.

### List View
- Lists all `tdbc_company_name_change_request` entries
- Columns: status (with color badges), created date, customer info
- Pagination, sorting, filtering support
- Refresh button

### Detail View
- Two cards: **Request Info** and **Customer Info**
- Request Info: old company name, new company name (highlighted), status, created/reviewed dates, review comment
- Customer Info: number, name, email, phone
- **Approve** and **Reject** buttons (only for pending requests)
- Optional review comment textarea for admin notes

### Routes (Admin API)

| Route | Method | Purpose |
|-------|--------|---------|
| `/api/topdata-better-checkout/company-name-change-request/{id}/approve` | POST | Approve a request |
| `/api/topdata-better-checkout/company-name-change-request/{id}/reject` | POST | Reject a request |

## Customer Address Extension

### sw-customer-address-form-options.html.twig
- Removes the "Set as default billing address" checkbox
- Disables "Set as default shipping address" when address is the billing address

### sw-customer-detail-addresses.html.twig
- Disables billing address radio selection (cannot change)
- Disables shipping address radio when it would match billing address
- Disables "Set as default billing address" context menu item
- Disables "Set as default shipping address" when address is billing address
