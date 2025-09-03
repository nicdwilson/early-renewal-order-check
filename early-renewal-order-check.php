<?php
/**
 * Plugin Name: Early Renewal Order Check
 * Plugin URI: https://github.com/automattic/early-renewal-order-check
 * Description: Prevents automatic subscription renewals when there's an early renewal order on hold within the past 3 weeks. We recommend adding a notification to admin using AutomateWoo and the note added trigger. The note contains the text "Scheduled renewal payment aborted: Found early renewal order on hold."
 * Version: 1.0.0
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Author: Growth Team
 * Author URI: https://automattic.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wcs-early-renewal-check
 * Domain Path: /languages
 * Network: false
 *
 * @package EarlyRenewalOrderCheck
 * @version 1.0.0
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Early Renewal Order Check Class
 *
 * This class adds a check at priority zero to the woocommerce_scheduled_subscription_payment
 * action to track whether there is an early renewal order for the subscription, and check
 * the status of that order. If the order is on hold and meets specific criteria, it aborts
 * the renewal process to prevent duplicate charges.
 *
 * The plugin checks for renewal orders that meet all three criteria:
 * 1. Order status is 'on-hold'
 * 2. Order has the '_subscription_renewal_early' meta flag
 * 3. Order was created within the past 3 weeks
 *
 * @package EarlyRenewalOrderCheck
 * @since 1.0.0
 */
class Early_Renewal_Order_Check {

	/**
	 * Flag to track if renewal should be aborted for specific subscriptions
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private static $abort_renewal = array();

	/**
	 * Early_Renewal_Order_Check The instance of Early_Renewal_Order_Check
	 *
	 * @var    object
	 * @access private
	 * @since  1.0.0
	 */
	private static object $instance;

	/**
	 * Main Early_Renewal_Order_Check Instance
	 *
	 * Ensures only one instance of Early_Renewal_Order_Check is loaded or can be loaded.
	 *
	 * @return Early_Renewal_Order_Check instance
	 * @since  1.0.0
	 * @static
	 */
	public static function instance(): object {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * Sets up the hooks for checking early renewal orders.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Add the check at priority 0 (before any other actions)
		add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'check_early_renewal_order' ), 0, 1 );
	}

	/**
	 * Check for early renewal orders and abort if on hold
	 *
	 * This method is called at priority 0 on the woocommerce_scheduled_subscription_payment
	 * action. It checks all renewal orders for the subscription and determines if any
	 * meet the criteria for aborting the automatic renewal.
	 *
	 * @param int $subscription_id The subscription ID to check
	 * @since 1.0.0
	 */
	public function check_early_renewal_order( $subscription_id ) {
		
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return;
		}

		// Get all renewal orders for this subscription.
		$renewal_orders = $subscription->get_related_orders( 'all', 'renewal' );

		if ( empty( $renewal_orders ) ) {
			return;
		}

		// Check if any renewal order meets all criteria.
		foreach ( $renewal_orders as $renewal_order ) {
			if ( ! is_object( $renewal_order ) ) {
				continue;
			}

			// Check if this renewal order meets all three criteria.
			if ( $this->meets_abort_criteria( $renewal_order ) ) {
				// Set the abort flag for this subscription
				self::$abort_renewal[ $subscription_id ] = $renewal_order->get_id();

				// Log the abort action
				$subscription->add_order_note(
					sprintf(
						__( 'Scheduled renewal payment aborted: Found early renewal order on hold. Order #%s created within past 3 weeks.', 'wcs-early-renewal-check' ),
						$renewal_order->get_order_number()
					)
				);

				$this->abort_prepare_renewal( $subscription_id );

				return;
			}
		}
	}

	/**
	 * Check if a renewal order meets all criteria for aborting the renewal
	 *
	 * Validates that the renewal order meets all three criteria:
	 * 1. Order status is 'on-hold'
	 * 2. Order has the '_subscription_renewal_early' meta flag
	 * 3. Order was created within the past 3 weeks
	 *
	 * @param WC_Order $renewal_order The renewal order to check
	 * @return bool True if the order meets all criteria, false otherwise
	 * @since 1.0.0
	 */
	private function meets_abort_criteria( $renewal_order ) {

		// Criterion 1: Is on hold.
		if ( ! $renewal_order->has_status( 'on-hold' ) ) {
			return false;
		}

		// Criterion 2: Is an early renewal order (check meta).
		$subscription_renewal_early = $renewal_order->get_meta( '_subscription_renewal_early' );

		if ( empty( $subscription_renewal_early ) ) {
			return false;
		}

		// Criterion 3: Created in the past three weeks.
		$three_weeks_ago = strtotime( '-3 weeks' );
		$order_date = $renewal_order->get_date_created();

		if ( ! $order_date ) {
			return false;
		}

		$order_timestamp = $order_date->getTimestamp();

		if ( $order_timestamp < $three_weeks_ago ) {
			return false;
		}
		// All criteria met
		return true;
	}

	/**
	 * Abort the prepare renewal process
	 *
	 * Removes the WooCommerce Subscriptions prepare_renewal and repair actions
	 * to prevent the renewal process from continuing.
	 *
	 * @param int $subscription_id The subscription ID
	 * @since 1.0.0
	 */
	public function abort_prepare_renewal( $subscription_id ) {
		if ( isset( self::$abort_renewal[ $subscription_id ] ) ) {
			// Remove the prepare_renewal action
			remove_action( 'woocommerce_scheduled_subscription_payment', array(
				'WC_Subscriptions_Manager',
				'prepare_renewal'
			), 1 );

			// Also remove the repair action
			remove_action( 'woocommerce_scheduled_subscription_payment', array(
				'WC_Subscriptions_Manager',
				'maybe_process_failed_renewal_for_repair'
			), 0 );
		}
	}

	/**
	 * Maybe abort the gateway payment process
	 *
	 * Removes the gateway payment action and clears the abort flag
	 * for the specified subscription.
	 *
	 * @param int $subscription_id The subscription ID
	 * @since 1.0.0
	 */
	public function abort_gateway_payment( $subscription_id ) {
		if ( isset( self::$abort_renewal[ $subscription_id ] ) ) {
			// Remove the gateway payment action
			remove_action( 'woocommerce_scheduled_subscription_payment', array( 'WC_Subscriptions_Payment_Gateways', 'gateway_scheduled_subscription_payment' ), 10 );

			// Clear the abort flag
			unset( self::$abort_renewal[ $subscription_id ] );
		}
	}
}

// Initialize the plugin
add_action( 'plugins_loaded', array( 'Early_Renewal_Order_Check', 'instance' ));
