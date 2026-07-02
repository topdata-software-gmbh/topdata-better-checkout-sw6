# [X] Company Name Change Requests

## Overview

Allows customers to request a company name change through a moderated workflow. The company name is stored in **two places**: the `customer` entity and the `customer_address` entity (billing address). When an admin approves a request, **both** are updated atomically.

## Database

Custom table `tdbc_company_name_change_request` created by migration `Migration1748979000`:

| Column | Type | Description |
|--------|------|-------------|
| `id` | BINARY(16) PK | UUID |
| `customer_id` | BINARY(16) FK → customer | The customer requesting the change |
| `address_id` | BINARY(16) FK → customer_address | The billing address being changed |
| `old_company_name` | VARCHAR(255) | Current company name |
| `new_company_name` | VARCHAR(255) | Requested new company name |
| `status` | VARCHAR(32) | `pending`, `approved`, or `rejected` |
| `reviewed_at` | DATETIME(3) NULL | When an admin acted |
| `review_comment` | VARCHAR(2000) NULL | Admin's review comment |
| `reviewed_by_user_id` | BINARY(16) NULL | Admin user who reviewed |
| `created_at` | DATETIME(3) | Auto |
| `updated_at` | DATETIME(3) NULL | Auto |

Entity definition: `CompanyNameChangeRequestDefinition` with associations to `customer` and `address` (CustomerAddress).

## Data Model

```
CompanyNameChangeRequestEntity
├── id : string
├── customerId : string           → CustomerEntity (ManyToOne)
├── addressId : string            → CustomerAddressEntity (ManyToOne)
├── oldCompanyName : string
├── newCompanyName : string
├── status : string               # pending | approved | rejected
├── reviewedAt : ?DateTimeInterface
├── reviewComment : ?string
├── reviewedByUserId : ?string
└── customer : ?CustomerEntity
└── address : ?CustomerAddressEntity
```

## Workflow

### 1. Customer Submits Request

- Customer clicks "Company name change" button on the billing address (read-only field)
- Modal opens with form showing current company name + input for new name
- **Route:** `POST /widgets/checkout/company-name-change-request/{addressId}` (storefront, Ajax)
- **Controller:** `BillingAddressEditController::submitCompanyNameChangeRequest()`
- Validates: new name not empty, not identical to current name
- Calls `CompanyNameChangeRequestService::createChangeRequest()`
- Any existing **pending** requests for the same address are automatically cancelled (rejected with reason "Automatically rejected due to new change request")
- Admin notification email sent if enabled

### 2. Pending State

While a request is pending:
- The `company` field on billing address forms is **read-only** (shown as text via `company-field-readonly.html.twig`)
- The `company` field on the profile edit form is **protected** by `ChangeCustomerProfileRouteDecorator::preserveCompany()`
- The `company` field on address upsert forms is **protected** by `UpsertAddressRouteDecorator::preserveBillingCompany()`
- A warning badge is displayed on address cards and profile page indicating a pending request
- Customer can edit the pending request (change the requested name)

### 3. Admin Approves

**Route:** `POST /api/topdata-better-checkout/company-name-change-request/{id}/approve` (admin API)
**Controller:** `CompanyNameChangeRequestController::approve()`

On approval, `CompanyNameChangeRequestService::approveChangeRequest()` does:

1. Updates request status to `approved`, sets `reviewedAt`
2. Updates `customer_address.company` — **the billing address's company name** via `customer_address.repository`
3. Updates `customer.company` — **the customer entity's company name** via `customer.repository`
4. Cancels any other pending requests for the same customer
5. Sends approval notification email to customer

**This is the key behavior:** the company name is renamed in **2 places**:
- `customer.company` (the customer record)
- `customer_address.company` (the billing address record identified by `addressId`)

### 4. Admin Rejects

**Route:** `POST /api/topdata-better-checkout/company-name-change-request/{id}/reject` (admin API)
**Controller:** `CompanyNameChangeRequestController::reject()`

On rejection:
1. Updates request status to `rejected`, sets `reviewedAt`
2. Sends rejection notification email to customer
3. No changes to customer or address data

## Service Layer

`CompanyNameChangeRequestService` provides:

| Method | Description |
|--------|-------------|
| `createChangeRequest(customerId, addressId, oldCompanyName, newCompanyName, context)` | Create pending request, cancel old ones, send admin email |
| `approveChangeRequest(changeRequestId, context, reviewComment?)` | Approve → update customer.company + customer_address.company |
| `rejectChangeRequest(changeRequestId, context, reviewComment?)` | Reject → no data changes |
| `updateChangeRequest(changeRequestId, newCompanyName, context)` | Customer edits pending request's new company name |
| `hasPendingChangeRequest(customerId, addressId, context)` | Check if specific address has pending request |
| `hasPendingChangeRequestForCustomer(customerId, context)` | Check if customer has any pending request |
| `findPendingChangeRequest(customerId, addressId, context)` | Find pending request by customer + address |
| `findPendingChangeRequestForCustomer(customerId, context)` | Find pending request by customer |

## Email Notifications

`CompanyNameChangeRequestEmailService` sends:

| Type | Template | Recipient |
|------|----------|-----------|
| Admin notification | `admin-company-name-change-notification.html.twig` | Plugin config email (falls back to `core.basicInformation.email` → `core.mailerSettings.mailerSender`) |
| Customer approved | `customer-company-name-approved.html.twig` | Customer email |
| Customer rejected | `customer-company-name-rejected.html.twig` | Customer email |

Controlled by config keys:
- `companyNameChangeNotificationEnabled` (bool, default `true`)
- `companyNameChangeNotificationEmail` (string, empty = fallback)

## Storefront Integration

### Read-Only Company Field

`company-field-readonly.html.twig` replaces the regular company input field on billing address forms:
- Displays the current company name as plain text
- Shows a "Change" / "Edit" button that opens the change request modal
- If a pending request exists, shows pending details below the company name

### Page Extensions (Subscribers)

Three subscribers attach `topdataCompanyNameChangePending` extension to storefront pages:

| Subscriber | Page | Check |
|-----------|------|-------|
| `CheckoutConfirmBlockSubscriber` | `CheckoutConfirmPageLoadedEvent` | Checks for ANY pending request for customer |
| `AccountAddressPageSubscriber` | `AddressListingPageLoadedEvent`, `AddressDetailPageLoadedEvent` | Checks for ANY pending request for customer |
| `AccountProfilePageSubscriber` | `AccountProfilePageLoadedEvent` | Checks for pending request specific to customer's `defaultBillingAddressId` |

## Company Name Protection

Two decorators prevent the company field from being emptied through standard forms:

| Decorator | Protects | Mechanism |
|-----------|----------|-----------|
| `UpsertAddressRouteDecorator::preserveBillingCompany()` | Billing address company (on address edit) | If submitted `company` is empty and address is customer's defaultBillingAddress, re-injects persisted value |
| `ChangeCustomerProfileRouteDecorator::preserveCompany()` | Customer entity company (on profile edit) | If submitted `company` is empty, re-injects persisted value from DB |

## Admin UI

Vue.js module `topdata-better-checkout-company-name-change` provides:
- **List view:** Lists all change requests with status badges, filtering, pagination
- **Detail view:** Shows old/new company, customer info, approve/reject buttons with optional review comment

## Related Config

```xml
<card title="Company Name Change Request">
    <input-field type="bool" name="companyNameChangeNotificationEnabled" default="true" />
    <input-field type="text" name="companyNameChangeNotificationEmail" default="" />
</card>
```
