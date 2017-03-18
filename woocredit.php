<?php

/*
 * Plugin Name: WooCredit
 * Plugin URL: https://www.whowp.com/woocredit
 * Description: WooCredit is a slick WooCommerce plugin that gives shoppers the ability to pay deposits by making a partial payment.
 * Version: 1.0
 * Author: WhoWP
 * Author URI: http://www.whowp.com/
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Enable automatic updates
 * 
 */
require 'plugin-updates/plugin-update-checker.php';

$plugin_updater = new PluginUpdateChecker( 'http://www.whowp.com/woocredit.txt', __FILE__ );


class wooq_deposit 
{
 /**
  * Constructor
  *
  */
  function __construct() 
  {
    // Set basic variables
    $this->nspace      = 'woo-deposits';
    $this->id          = 'deposits';
		$this->label       = __( $this->id, 'woocommerce' );
		
		// Import script file(s)
		add_action( 'admin_enqueue_scripts', array( $this, 'wooq_deposit_enqueue_admin_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'wooq_deposit_enqueue_frontend_scripts' ) );
		
		// Import CSS file(s)
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		// Initialize settings page
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_menu_item' ), 21 );
		add_action( 'woocommerce_settings_tabs_deposits', array( $this, 'settings_page' ) );
		add_action( 'woocommerce_update_options_deposits', array( $this, 'save_settings' ) );
		
		// Display the cart message 
		add_action( 'woocommerce_before_cart', array( $this, 'display_cart_message' ) );
		
		// Add selection form for payment options
		add_action( 'woocommerce_checkout_after_customer_details', array( $this, 'payment_options_form' ) );
		
		// Ajax callback
		add_action( 'wp_ajax_calculate_deposit', array( $this, 'calculate_deposit_callback' ) );
    add_action( 'wp_ajax_nopriv_calculate_deposit', array( $this, 'calculate_deposit_callback' ) );
    
    // Add hidden deposit amount field
    add_action( 'woocommerce_after_order_notes', array( $this, 'add_checkout_fields' ) );
    
    // Hook into the order process and do some magic
    add_action( 'woocommerce_checkout_order_processed', array( $this, 'checkout_order_processed' ) );
    
    // Include classes
    add_action( 'init', array( $this, 'load_classes' ) );
    
    // Add filter for cart totals
    add_filter( 'woocommerce_calculated_total', array( $this, 'apply_deposit' ), 10, 2 ); 
        
    //HERE. We need a filter for when the payment has been made, and undo the deposit on completion of that, so the totals are not messed up afterwards.
    add_filter( 'woocommerce_payment_successful_result', array( $this, 'undo_deposit' ), 10, 2);
    
    
    // Add hooks to update email templates
    add_action( 'woocommerce_email_after_order_table', array( $this, 'email_after_order_table' ), 10, 2 );
    add_filter( 'woocommerce_get_order_item_totals', array( $this, 'order_item_totals' ), 10, 2 );
    
    // Hook for when the cart page loads
    // add_action( 'woocommerce_before_cart', array( $this, 'after_cart' ) );
    add_action( 'woocommerce_payment_complete', array( $this, 'payment_complete' ) );
    
    
    // Add the tab to edit per-product deposits within the product editor
    add_action('woocommerce_product_write_panel_tabs', array( $this, 'deposit_tab_options_tab') );
    add_action('woocommerce_product_write_panels', array( $this, 'deposit_tab_options') );
    add_action('woocommerce_process_product_meta', array( $this, 'process_product_meta_deposit_tab') );
  }
  
  
 /**
  * @return void
  */
  public function order_item_totals( $total_rows, $order )
  {
  
    // Do not proceed if deposit feature is disabled
    if ( get_option( 'woo_payment_option' ) == 'disabled' ) {
      return false;
    }
  
    $order_total = get_post_meta( $order->id, 'order_total', true );
    $paid_today = get_post_meta( $order->id, 'deposit_amount', true );
    $remaining_balance = get_post_meta( $order->id, 'remaining_balance', true );

    $formatted_order_total = money_format( get_woocommerce_currency_symbol() . '%n', $order_total );
    $formatted_paid_today = money_format( get_woocommerce_currency_symbol() . '%n', $paid_today );
    $formatted_remaining_balance = money_format( get_woocommerce_currency_symbol() . '%n', $remaining_balance );
    
    //We need to remove the deposit amount from the 'discount'.
    unset($total_rows['cart_discount']);
    
    $total_rows['paid_today'] = array(
      'label' => __( 'Deposit Paid:', $this->nspace ),
      'value' => $formatted_paid_today
    );
    
    $total_rows['remaining_amount'] = array(
      'label' => __( 'Remaining Amount:', $this->nspace ),
      'value' => $formatted_remaining_balance
    );
    
    $total_rows['order_total'] = array(
      'label' => __( 'Order Total:', 'woocommerce' ),
      'value' => $formatted_order_total
    );
    
    return $total_rows;
  }
  
  
 /**
  *
  * @return void
  */
  public function email_after_order_table($order, $is_admin)
  {
    // Do not proceed if deposit feature is disabled
    if ( get_option( 'woo_payment_option' ) == 'disabled' ) {
      return false;
    }
  
    // Only for admin emails
    if ( $is_admin == false ) {
    
      $woo_email_note = get_option( 'woo_email_note' );

      if ( $woo_email_note ) {

        if ( strlen( $woo_email_note ) > 1 ) {

          $new_section = '<h2>Deposit Notes</h2>';
          $new_section .= '<p>' . get_option( 'woo_email_note' ) . '</p>';
      
          echo $new_section;
        }
      }
    }
  }
  
  /**
   * Calculate the deposit required, given a cart object
   *
   * @return value of the calculated deposit
   */
  public function calculate_deposit_from_cart( $cart ){
    $deposit_value = get_option( 'woo_deposit_value' );
    
    //Ignore product-specific overrides if a fixed global value is set
    if ( get_option( 'woo_deposit_type' ) == 'fixed' ) {
      return $deposit_value;
    }
    
    $sum_deposit_values = 0;  
    //We need to see if any individual product overrides exist for whatever is in the cart at the moment.
    foreach ( $cart as $cart_item_key => $values ) {
    
      // If we've set a deposit override, calculate it for this product and add it to the total deposit.
      if ( get_post_meta( $values['product_id'], 'woo_deposits:is_deposit_override', true) == 1 ){
        //We need to check if we're calculating for a fixed value, or for a percentage.
        $deposit_amount_type = get_post_meta( $values['product_id'], 'woo_deposits:deposit_amount_type', true );
        $product_deposit_value = get_post_meta ( $values['product_id'], 'woo_deposits:deposit_override_value', true );
        
        //If the override is a fixed value, just add the value to the total deposit
        if ( $deposit_amount_type == 'fixed' ) {
          $sum_deposit_values += $product_deposit_value;
        } else {
          //The override is a calculation based on percent. So do that, then add it to the total deposit.
          $sum_deposit_values += ( $values['line_total'] / 100 ) * $product_deposit_value;
        }
      } else{
        //We're using the value set in the WooCommerce override, which we now know is a percentage
        $sum_deposit_values += ( $values['line_total'] / 100 ) * $deposit_value;
      }
    }
    

    return $sum_deposit_values;
  }
  
  
 /**
  * Update the cart total given the deposit options
  *
  * @param 
  * @param 
  * @return void
  */
  public function apply_deposit( $cart_total, $cart )  {

    // Do not proceed if deposit feature is disabled
    if ( get_option( 'woo_payment_option' ) == 'disabled' ) {
    
      return $cart_total;
    }
    
    // If this is the cart page we want to return the actual cart total
    if ( is_cart() || is_shop() ) {
      return $cart_total;
    }

    // The purchaser's choice either set to 'full' or 'deposit' 
    $deposit_option = WC()->session->get( 'deposit_option' );
  
    if ( $deposit_option == 'deposit' ) {
      
      // $deposit_value is the back-end value added for the total cart deposit
      $deposit_value = get_option( 'woo_deposit_value' );
            
      if ( $this->is_fixed() ) {
        //Set the discount value on the cart, so that PayPal will use the correct amount
        $cart->discount_cart += ($cart->subtotal - $deposit_value );//+ $cart->shipping_total);
        return $deposit_value;
      } else {
        $cart->discount_cart += ($cart->subtotal - $this->calculate_deposit_from_cart($cart->get_cart()) );//+ $cart->shipping_total );
        return $this->calculate_deposit_from_cart($cart->get_cart());
      }
    } else {
      return $cart_total;
    }
  }
  
 /**
  * Update the cart total given the deposit options. This is required so that the e-mails and other meta data show the right 'Order Total'
  *
  * @param 
  * @param 
  * @return void
  */
  public function undo_deposit( $result, $order_id ) {    
    $order = new WC_Order( $order_id );

    update_post_meta($order->id, '_order_total', get_post_meta( $order->id, 'order_total', true ) );
    update_post_meta($order->id, '_cart_discount', 0 );
    update_post_meta($order->id, '_order_discount', 0 );
    
    $order->order_total = get_post_meta( $order->id, 'order_total', true );
    $order->cart_discount = 0;
    $order->order_discount = 0;    
  
    return $result;
  }
  
  
 /**
  * True if the deposit type is fixed, false if the deposit type is a percentage. 
  *
  * @return returns true if fixed, false if percentage.
  */
  public function is_fixed() {
    $deposit_type = get_option( 'woo_deposit_type' );
    
    if ( $deposit_type == 'fixed' ) {
      return true;
    } else {
      return false;
    }
  }
  
  
 /**
  *
  * Add any fields that we may want to pass through at checkout time to be saved in the order metadata. 
  *
  * @return void
  */
  public function add_checkout_fields( $checkout ) {
    
    // Do not proceed if deposit feature is disabled
    if ( get_option( 'woo_payment_option' ) == 'disabled' ) {
      return false;
    }
    
    woocommerce_form_field( 'deposit_amount_hidden', array( 
  		'type' 			=> 'text', 
  		'class' 		=> array( 'deposit_amount_hidden' )
		), $checkout->get_value( 'deposit_amount_hidden' ) );
		
		woocommerce_form_field( 'remaining_balance_hidden', array( 
  		'type' 			=> 'text', 
  		'class' 		=> array( 'remaining_balance_hidden' )
		), $checkout->get_value( 'remaining_balance_hidden' ) );
		
		woocommerce_form_field( 'total_order_cost_hidden', array( 
  		'type' 			=> 'text', 
  		'class' 		=> array( 'total_order_cost_hidden' )
		), $checkout->get_value( 'total_order_cost_hidden' ) );
  }


 /**
  *
  * Called when the checkout button is clicked and an order is processed by WooCommerce.
  *
  * This function adds the deposit amount to the order metadata, always as a fixed amount. 
  *
  * Even deposits calculated as a percentage are added as a fixed amount.
  *
  * @return void
  */
  public function checkout_order_processed( $order_id ) {
    
    // Do not proceed if deposit feature is disabled
    if ( get_option( 'woo_payment_option' ) == 'disabled' ) {
      return false;
    }
        
    //Ensure we don't pass in null values, otherwise we'll flip out when sending e-mails
    $_POST['total_order_cost_hidden'] == ''   ? $_POST['total_order_cost_hidden'] = 0   : $_POST['total_order_cost_hidden'] = $_POST['total_order_cost_hidden'] ;
    $_POST['deposit_amount_hidden'] == ''     ? $_POST['deposit_amount_hidden'] = 0     : $_POST['deposit_amount_hidden'] = $_POST['deposit_amount_hidden'] ;
    $_POST['remaining_balance_hidden'] == ''  ? $_POST['remaining_balance_hidden'] = 0  : $_POST['remaining_balance_hidden'] = $_POST['remaining_balance_hidden'] ;
        
    update_post_meta( $order_id, 'order_total', esc_attr( $_POST[ 'total_order_cost_hidden' ] ) );
    update_post_meta( $order_id, 'deposit_amount', esc_attr( $_POST[ 'deposit_amount_hidden' ] ) );
    update_post_meta( $order_id, 'remaining_balance', esc_attr( $_POST[ 'remaining_balance_hidden' ] ) );
  }
  
  /**
	 * Loads additional classes
	 *
	 * @return void
	 */
	public function load_classes() {
	}
  
  
 /**
  * Called when the purchser switches between full payment and deposit.
  * 
  * @return void
  */
	public function calculate_deposit_callback() {
	
    // Do not proceed if deposit feature is disabled
    if (get_option( 'woo_payment_option' ) == 'disabled') {
    
      return false;
    }
    
	  if ($_POST['deposit_option'] == 'full') {
  		
  		WC()->session->set( 'deposit_option', 'full' );
		} else {
  		
  		WC()->session->set( 'deposit_option', 'deposit' );
		}
		
		echo $this->calculate_cart_total( WC()->cart );
		
		die();
	}
  
  
 /**
  * Enqueue CSS file(s)
  *
  * @return void
  */
  public function enqueue_styles() 
  {
    wp_enqueue_style( 'woo-deposits-frontend-style', plugins_url( 'css/style.css', __FILE__ ) );
  }
  
  
 /**
  * Enqueue backend/admin JavaScript file(s)
  * 
  * @return void
  */	
	public function wooq_deposit_enqueue_admin_scripts() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'woo-deposits-admin-script', plugins_url( 'js/admin.js', __FILE__ ) );
    wp_enqueue_style( 'woo-deposits-frontend-style', plugins_url( 'css/admin-style.css', __FILE__ ) );
	}
  
  
	/**
	 * Enqueue frontend/GUI JavaScript file(s)
	 * 
	 * @return void
	 */	
	public function wooq_deposit_enqueue_frontend_scripts() {	  
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'woo-deposits-frontend-script', plugins_url( 'js/frontend.js', __FILE__ ) );
		
		$data = array( 
		  'ajax_url' => admin_url( 'admin-ajax.php' ),
		  'payment_option' => get_option( 'woo_payment_option' ),
		  'deposit_value' =>  $this->calculate_deposit_from_cart( $cart = WC()->session->get( 'cart', array() ) ),
		  'deposit_option' => $deposit_option = WC()->session->get( 'deposit_option' ),
		  'currency_sign' => get_woocommerce_currency_symbol(),
		  'currency_format' => get_option( 'woocommerce_currency_pos' )
		);
    wp_localize_script('woo-deposits-frontend-script', 'php_data', $data);
	}
	
	public function calculate_cart_total( $cart ) {
	
	  $coupons = $cart->applied_coupons;
	  $free_shipping = false;
	  
	  foreach ( $cart->applied_coupons as $coupon_code ) {
  	  $coupon = new WC_Coupon( $coupon_code );
  	  if ( $coupon->enable_free_shipping() ) {
    	  $free_shipping = true;
    	  break;
  	  }
	  }
	
    //We don't want to return the shipping value, because we do that manually in JS...
	
/* 	  if ( $free_shipping ) { */
	    return round( $cart->cart_contents_total + $cart->tax_total + $cart->fee_total, $cart->dp );  
/* 	  } */
/* 	  return round( $cart->cart_contents_total + $cart->tax_total + $cart->shipping_tax_total + $cart->shipping_total + $cart->fee_total, $cart->dp ); */
	}
  
  
  /**
   * Creates the payment options form that allows the user to select whether to
   * pay the deposit amount or the full amount on the checkout page.
   *
   * @return void
   */
  public function payment_options_form() {
  
    // Do not proceed if deposit feature is disabled
    if (get_option( 'woo_payment_option' ) == 'disabled') {
      return false;
    }
    
    if ( get_option( 'woo_payment_option' ) == 'allowed' ) {
      ?>
      
      <div id='deposit-options-form'>
      
        <h3><?php echo 'Deposit Option'; ?></h3>
      
        <ul class="deposit_options">
        
          <li id='deposit-option-full' class="deposit_option deposit_option_full">
    				<input id='pay-full-amount' name='deposit-radio' type='radio' checked='checked' class='input-radio'>
    				<label for='pay-full-amount'>Pay full amount</label>
    			</li>	
    			
          <li id='deposit-option-partial' class="deposit_option deposit_option_partial">
    				<input id='pay-deposit' name='deposit-radio' type='radio' class='input-radio'>
    				<label for='pay-deposit'>Pay deposit</label>
    			</li>
    			
        </ul>
    
      </div>
      
      <?php
    } else if ( get_option( 'woo_payment_option' ) == 'forced' ) {
      ?>
      
      <div id='deposit-options-form'>
      
        <div id='deposit-option-full' hidden>
  				<input id='pay-full-amount' name='deposit-radio' type='radio' class='input-radio'>
  				<label for='pay-full-amount'>Pay full amount</label>
  			</div>	
  			
        <div id='deposit-option-partial'>
  				<input id='pay-deposit' name='deposit-radio' type='radio' checked='checked' class='input-radio'>
  				<label for='pay-deposit'>Pay deposit</label>
  			</div>
    
      </div>
      
      <?php
    }
  }
 
  
  /**
	 * Displays the cart message text at the top of the cart page. 
	 * 
	 * @return void
	 */
  public function display_cart_message( $param ) {
  
    // Do not proceed if deposit feature is disabled, or we're asking for full payment (100%)
    if (get_option( 'woo_payment_option' ) == 'disabled' ) {
      return false;
    }
  
    //Do not proceed if deposits are not available
    if ( $this->calculate_deposit_from_cart( WC()->cart->get_cart()) == 0 &&
       ( get_option( 'woo_deposit_value' ) == 100 && get_option( 'woo_deposit_type' ) == 'percentage' ) ){
      return false;
    }
  
    // Retrieve and output cart message to the frontend
    $cart_message = get_option( 'woo_cart_message' );
    
    if ( ! empty( $cart_message ) ) {
			?>
				<div class="woocommerce-message">
					<?php echo $cart_message; ?>
				</div>
			<?php 
		}	
  }
  
  
	/**
	 * Adds a new tab to the settings area
	 *
	 * @param tabs an array containing the settings tabs
	 * @return void
	 */
	public function add_menu_item( $tabs ) 
	{
		$tabs[ $this->id ] = __( 'Deposits', 'woo-deposits' );

		return $tabs;
	}
  
  
	/**
	 * Save settings
	 * 
	 * @return void
	 */
  public function save_settings() 
  {
    $payment_options = '';
		if ( isset( $_POST['woo_payment_option'] ) ) 
		{	 
			$payment_options = $_POST['woo_payment_option'];
			update_option('woo_payment_option', $payment_options);
		}
		
		$deposit_type = '';
		if ( isset( $_POST['woo_deposit_type'] ) ) 
		{	 
			$deposit_type = $_POST['woo_deposit_type'];
			update_option('woo_deposit_type', $deposit_type);
		}
		
		$deposit_value = '';
		if ( isset( $_POST['woo_deposit_value'] ) && is_numeric( $_POST['woo_deposit_value'] ) ) 
		{	 
			$deposit_value = $_POST['woo_deposit_value'];
			update_option('woo_deposit_value', $deposit_value);
		}
		
		$cart_message = '';
		if ( isset( $_POST['woo_cart_message'] ) ) 
		{	 
			$cart_message = $_POST['woo_cart_message'];
			update_option('woo_cart_message', $cart_message);
		}
		
		$email_notice = '';
		if ( isset( $_POST['woo_email_note'] ) ) 
		{	 
			$email_notice = $_POST['woo_email_note'];
			update_option('woo_email_note', $email_notice);
		}
  }
  
  /**
   * Add the Deposits tab within the product editor -> product data meta box
   *
   * @return void
   */
  function deposit_tab_options_tab() 
  {
  ?> <li class="deposit_tab"><a href="#deposit_tab_data"><?php _e('Deposit Options', 'woothemes'); ?></a></li> <?php
  }
  
  /**
   * Deposit Tab Options
   *
   * Provides the input fields and add/remove buttons for booking tabs on the single product page.
   */
  function deposit_tab_options()
  {
    global $post;
    
    // Set some defaults
    if ( get_post_meta( $post->ID, 'woo_deposits:deposit_override_value', true) == '' ) update_post_meta( $post->ID, 'woo_deposits:deposit_override_value', 0 );
    $deposit_override_value = get_post_meta( $post->ID, 'woo_deposits:deposit_override_value', true );
    $deposit_amount_type = get_post_meta( $post->ID, 'woo_deposits:deposit_amount_type', true );
    
    ?>
      <div id="deposit_tab_data" class="panel woocommerce_options_panel">
        <div class="options_group">

          <p class="form-field">
            <?php woocommerce_wp_checkbox( array( 'id' => 'woo_deposits:is_deposit_override', 'cbvalue' => '1', 'label' => __('Deposit Override?', 'woothemes'), 'description' => 'Does this product have a specific deposit, different from the global setting? Please note that this will be ignored if the global deposit is set as a fixed value.' ) ); ?>
          </p>
        </div>
        
        <div class="options_group">
          <p class="form-field">
            <span class="">
              <label for="amount_type"> Specify type of deposit amount </label>
              <input type="radio" name="amount_type" value="fixed" <?php echo ($deposit_amount_type == 'fixed') ? 'checked' : ''; ?>> <span class="description"> Fixed Dollar Value </span> <br>
              <input type="radio" name="amount_type" value="percent" <?php echo ($deposit_amount_type == 'percent') ? 'checked' : ''; ?>> <span class="description"> Percentage of Price </span>
            </span>
          </p>
        </div>
        
        <div class="options_group">
          <p class="form-field"><label for=".">Amount</label><input type="text" class="short" name="woo_deposits:deposit_override_value" value="<?php echo $deposit_override_value ;?>" > </p>          
        </div>
      </div>
    <?php
  }
  
  /**
   * Processes the deposit tab options when a post is saved
   *
   */
  function process_product_meta_deposit_tab( $post_id ) { 
    update_post_meta( $post_id, 'woo_deposits:is_deposit_override', isset($_POST['woo_deposits:is_deposit_override']) &&  $_POST['woo_deposits:is_deposit_override'] ? '1' : '0' );
    update_post_meta( $post_id, 'woo_deposits:deposit_override_value', is_numeric($_POST['woo_deposits:deposit_override_value']) ? $_POST['woo_deposits:deposit_override_value'] : '0' );
    
    if ( isset($_POST['amount_type'])) {
      update_post_meta( $post_id, 'woo_deposits:deposit_amount_type', $_POST['amount_type']);
    }
  }



  
	/**
	 * Settings page view
	 *
	 * @return void
	 */
	public function settings_page() 
	{
		global $woocommerce_settings, $woocommerce;
		
		$settings_title = array( 'name' => __('Deposit Settings', $this->nspace), 'type' => 'title', 'id' => 'woo_deposits_options_site_wide' );
		
    $payment_options = array(
      'name'     => __( 'Payment Options', $this->nspace ),  
      'desc_tip' => __( 'Lets you choose whether to Allow the purchaser to decide to pay the full amount or just the deposit. You can disable the deposit feature here if you wish.' ),
      'id'       => 'woo_payment_option',
      'type'     => 'select',
      'css'      => 'min-width:350px;',
      'options'  => array(
				'allowed'      => __( 'Allow Customer Choice', $this->nspace ),
				'forced'   => __( 'Force Deposits', $this->nspace ),
				'disabled'    => __( 'Disable Deposits', $this->nspace )
			),
			'default'  => 'allowed',
			'desc'     => __( 'Allow purchaser to choose between paying the  full amount or leaving a deposit', 'select' )
    );
    
    $deposit_type = array(
      'name'     => __( 'Deposit Type', $this->nspace ),  
      'desc_tip' => __( 'Lets you choose how deposits are calculated throughout the store. Either a fixed dollar amount or a percentage of the product price.' ),
      'id'       => 'woo_deposit_type',
      'type'     => 'select',
      'css'      => 'min-width:350px;',
      'options'  => array(
				'percentage'        => __( 'Percentage', $this->nspace ),
				'fixed'             => __( 'Fixed', $this->nspace )
			),
			'default'  => 'percentage',
      'desc'     => __( 'Calculate deposit as a percentage or use fixed amount', 'select' )
    );
    
    $deposit_value = array(
      'name'     => __( 'Deposit Value', $this->nspace ),  
      'desc_tip' => __( 'Lets you choose the deposit value as either a fixed amount or a percentage.' ),
      'id'       => 'woo_deposit_value',
      'type'     => 'text',
      'css'      => 'min-width:350px;',
      'default'  => '10',
      'desc'     => __( '', 'text' )
    );
    
    $cart_message = array(
      'name'     => __( 'Cart Message', $this->nspace ),  
      'desc_tip' => __( 'The message that will appear on the cart page relating to making deposits.' ),
      'id'       => 'woo_cart_message',
      'type'     => 'textarea',
      'css'      => 'min-width:350px; min-height: 90px;'
    );
    
    $email_notice = array(
      'name'     => __( 'Email Note', $this->nspace ),  
      'desc_tip' => __( 'This note will be included in emails sent to the purchaser.' ),
      'id'       => 'woo_email_note',
      'type'     => 'textarea',
      'css'      => 'min-width:350px; min-height: 90px;'
    );
    
    $woocommerce_settings[$this->id] = array( $settings_title, $payment_options, $deposit_type, $deposit_value, $cart_message, $email_notice, array( 'type' => 'sectionend', 'id' => 'test-options' ) );
    
		woocommerce_admin_fields( $woocommerce_settings[$this->id] );
  }
}

$GLOBALS['wooq_deposit'] = new wooq_deposit();

?>