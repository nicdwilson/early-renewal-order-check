# Early Renewal Order Check

A WordPress plugin that prevents automatic subscription renewals and customer notifications when there's an early renewal order on hold within the past 3 weeks. We recommend adding a notification to admin using AutomateWoo and the note added trigger. The note contains the text "Scheduled renewal payment aborted: Found early renewal order on hold."

## Description

The Early Renewal Order Check plugin adds a safety mechanism to WooCommerce Subscriptions that checks for existing early renewal orders before processing automatic renewals and sending customer notifications. When a scheduled subscription payment or customer notification is triggered, this plugin runs at priority 0 (before other actions) to:

1. Check if there are any renewal orders for the subscription
2. Verify if any renewal order meets the abort criteria:
   - Order status is 'on-hold'
   - Order has the `_subscription_renewal_early` meta flag
   - Order was created within the past 3 weeks
3. If criteria are met, abort the renewal process and add a note to the subscription

## Problem Solved

This plugin addresses the issue described in [WOOSUBS-175](https://linear.app/a8c/issue/WOOSUBS-175/when-a-manual-early-renewal-is-awaiting-confirmation-of-payment) where manual early renewals that are awaiting payment confirmation could conflict with automatic scheduled renewals, potentially causing duplicate charges or payment processing issues. It also prevents unnecessary customer notifications when there's already an early renewal order pending.

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- WooCommerce Subscriptions 2.0 or higher
- PHP 7.4 or higher

## Installation

1. Upload the `early-renewal-order-check.php` file to the `/wp-content/plugins/early-renewal-order-check/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. No additional configuration is required

## How It Works

### Hook Priority
The plugin hooks into two actions at priority 0, ensuring it runs before any other subscription processing:

1. `woocommerce_scheduled_subscription_payment` - for payment processing
2. `woocommerce_scheduled_subscription_customer_notification_renewal` - for customer notifications

### Abort Criteria
A renewal order must meet ALL of the following criteria to trigger an abort:

1. **Status Check**: Order must have 'on-hold' status
2. **Early Renewal Flag**: Order must have the `_subscription_renewal_early` meta field set
3. **Time Window**: Order must have been created within the past 3 weeks

### Abort Process
When criteria are met, the plugin:

1. Sets an abort flag for the subscription
2. Adds a note to the subscription explaining the abort
3. For payment processing:
   - Removes the WooCommerce Subscriptions `prepare_renewal` action (priority 1)
   - Removes the `maybe_process_failed_renewal_for_repair` action (priority 0)
4. For customer notifications:
   - Removes the `send_customer_renewal_notification` action (priority 10)

## Code Structure

### Main Class: `Early_Renewal_Order_Check`

The plugin uses a singleton pattern with the following key methods:

- `instance()`: Returns the singleton instance
- `check_early_renewal_order()`: Main check method called on scheduled payments
- `check_early_renewal_order_notification()`: Check method called on customer notifications
- `meets_abort_criteria()`: Validates if an order meets abort criteria
- `abort_prepare_renewal()`: Removes renewal processing actions
- `abort_customer_notification()`: Removes customer notification actions
- `abort_gateway_payment()`: Removes gateway payment actions

### Static Properties

- `$abort_renewal`: Array tracking subscriptions that should have renewals aborted
- `$instance`: Singleton instance of the class

## Usage

The plugin works automatically once activated. No manual intervention is required. When a subscription renewal is scheduled:

1. The plugin checks for early renewal orders
2. If an early renewal order on hold is found within 3 weeks, the automatic renewal is aborted
3. A note is added to the subscription explaining the abort
4. The renewal process stops before any payment processing occurs

## Logging

When an abort occurs, the plugin adds a note to the subscription with one of the following formats:

**For payment processing:**
```
Scheduled renewal payment aborted: Found early renewal order on hold. Order #[order_number] created within past 3 weeks.
```

**For customer notifications:**
```
Scheduled renewal customer notification aborted: Found early renewal order on hold. Order #[order_number] created within past 3 weeks.
```

## Development

### Testing
To test the plugin:

1. Create a subscription
2. Create an early renewal order for that subscription
3. Set the order status to 'on-hold'
4. Add the `_subscription_renewal_early` meta field
5. Ensure the order is less than 3 weeks old
6. Trigger a scheduled renewal payment
7. Verify the renewal is aborted and a note is added

### Customization
The plugin can be extended by:

- Modifying the time window in `meets_abort_criteria()`
- Adding additional criteria checks
- Customizing the abort message
- Adding additional abort actions

## Changelog

### 1.0.0
- Initial release
- Basic early renewal order checking functionality
- Abort mechanism for conflicting renewals

## Support

For issues and feature requests, please refer to the original Linear ticket: [WOOSUBS-175](https://linear.app/a8c/issue/WOOSUBS-175/when-a-manual-early-renewal-is-awaiting-confirmation-of-payment)

## License

This plugin is licensed under the GPL v2 or later.

## Contributing

This plugin is maintained by the Growth Team at Automattic. For contributions, please follow WordPress coding standards and ensure all changes are properly documented.


