# 3-Box Checkout Selection Screen

## Overview

Replaces the default Shopware `/checkout/address` page with a conversion-optimized 3-box selection:
- **Register** — full registration form
- **Login** — inline login form
- **Guest** — guest checkout form

## Implementation

- **Template:** `src/Resources/views/storefront/page/checkout/address/index.html.twig`
  - Overrides `page_checkout_main_content` block
  - Shows 3 selection cards when no `checkoutType` is set and customer is not logged in
- **Template:** `src/Resources/views/storefront/page/checkout/address/register.html.twig`
  - Overrides `page_checkout_register_personal_guest` block
  - Manages guest/register checkbox state

## CheckoutType Flow

1. User selects "Register" or "Guest" on the 3-box screen
2. `checkoutType` parameter (`register` or `guest`) is set as query param
3. Flows through hidden form input → `RequestDataBag`
4. Persistent throughout the registration process

## Guest Checkout Password Management

- **Guest flow:** hidden checked `guest=1` + hidden `createCustomerAccount` checkbox → Shopware JS hides password fields
- **Register flow:** hidden unchecked `createCustomerAccount` → password fields visible
