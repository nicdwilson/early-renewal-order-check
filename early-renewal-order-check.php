<?php
/**
 * Plugin Name: Early Renewal Order Check
 * Description: Checks for early renewal orders and aborts renewal process if order is on hold
 * Version: 1.0.0
 * Author: Growth Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Early Renewal Order Check Class
 *
 * This class adds a check at priority zero to the woocommerce_scheduled_subscription_payment
 * action to track whether there is an early renewal order for the subscription, and check
 * the status of that order. If the order is on hold, it aborts the renewal process.
 */
class Early_Renewal_Order_Check_Final {

	/**
	 * Flag to track if renewal should be aborted
	 *
	 * @var array
	 */
	private static $abort_renewal = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		// Add the check at priority 0 (before any other actions)
		add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'check_early_renewal_order' ), 0, 1 );

		// Hook into the prepare_renewal function to prevent it from running
		add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'maybe_abort_prepare_renewal' ), 0, 1 );

		// Hook into the gateway payment function to prevent it from running
		add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'maybe_abort_gateway_payment' ), 9, 1 );
	}

	/**
	 * Check for early renewal orders and abort if on hold
	 *
	 * @param int $subscription_id The subscription ID
	 */
	public function check_early_renewal_order( $subscription_id ) {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return;
		}

		// Get all renewal orders for this subscription
		$renewal_orders = $subscription->get_related_orders( 'all', 'renewal' );

		if ( empty( $renewal_orders ) ) {
			return;
		}

		// Check if any renewal order meets all criteria
		foreach ( $renewal_orders as $renewal_order ) {
			if ( ! is_object( $renewal_order ) ) {
				continue;
			}

			// Check if this renewal order meets all three criteria
			if ( $this->meets_abort_criteria( $renewal_order ) ) {
				// Set the abort flag for this subscription
				self::$abort_renewal[ $subscription_id ] = $renewal_order->get_id();

				// Log the abort action
				$subscription->add_order_note(
					sprintf(
						__( 'Scheduled renewal payment aborted: Found early renewal order #%s on hold (created within past 2 weeks).', 'woocommerce-subscriptions' ),
						$renewal_order->get_order_number()
					)
				);

				$this->abort_prepare_renewal();

				return;
			}
		}
	}

	/**
	 * Check if a renewal order meets all criteria for aborting the renewal
	 *
	 * @param WC_Order $renewal_order The renewal order to check
	 * @return bool True if the order meets all criteria
	 */
	private function meets_abort_criteria( $renewal_order ) {
		// Criterion 1: Created in the past two weeks
		$two_weeks_ago = strtotime( '-2 weeks' );
		$order_date = $renewal_order->get_date_created();

		if ( ! $order_date ) {
			return false;
		}

		$order_timestamp = $order_date->getTimestamp();

		if ( $order_timestamp < $two_weeks_ago ) {
			return false;
		}

		// Criterion 2: Is an early renewal order (check meta)
		$subscription_renewal_early = $renewal_order->get_meta( '_subscription_renewal_early' );

		if ( empty( $subscription_renewal_early ) ) {
			return false;
		}

		// Criterion 3: Is on hold
		if ( ! $renewal_order->has_status( 'on-hold' ) ) {
			return false;
		}

		// All criteria met
		return true;
	}

	/**
	 * Maybe abort the prepare renewal process
	 *
	 * @param int $subscription_id The subscription ID
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
	 * @param int $subscription_id The subscription ID
	 */
	public function maybe_abort_gateway_payment( $subscription_id ) {
		if ( isset( self::$abort_renewal[ $subscription_id ] ) ) {
			// Remove the gateway payment action
			remove_action( 'woocommerce_scheduled_subscription_payment', array( 'WC_Subscriptions_Payment_Gateways', 'gateway_scheduled_subscription_payment' ), 10 );

			// Clear the abort flag
			unset( self::$abort_renewal[ $subscription_id ] );
		}
	}
}

// Initialize the plugin
new Early_Renewal_Order_Check_Final();
