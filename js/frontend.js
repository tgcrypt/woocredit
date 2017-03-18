jQuery(function($) {

  var ajax_url = php_data.ajax_url;
  
  var payment_option = php_data.payment_option;  // allowed, forced or disabled
  var cart_total = -1;                           // cart total as a number 
  var currency_sign = php_data.currency_sign;    // currency sign (pound, dollar, euro, etc)
  var currency_format = php_data.currency_format;// position of currency (left, right, w/w/o spaces)
  var deposit_value = php_data.deposit_value;    // deposit value (either as a percentage or fixed depending on deposit type)
  var deposit_option = php_data.deposit_option;  // the deposit option - either 'full' or 'deposit' - choice made by purchaser

  $( document ).ready(function() {
    // Set default non-null values for the order meta fields.
    if($('tr.order-total .amount').length > 0){ 
      $( '#total_order_cost_hidden' ).val( $('tr.order-total .amount').html().substr(1) );
    }
    $( '#deposit_amount_hidden' ).val( 0 );
    $( '#remaining_balance_hidden' ).val( 0 );
    
    // Update the radio buttons accordingly
    if (deposit_option == 'deposit') {
      $( '#pay-deposit' ).attr('checked', 'checked');
    }
    
    // Make sure we listen to when the checkout is updated
  	$('body').bind('updated_checkout', function() {
  		// Re-apply deposit
  		apply_deposit();
  	});
    
  });

  $( '#deposit-option-full' ).click( function( event ) {
	
    process_option_change();
		
		return true;
	});
	
	$( '#deposit-option-partial' ).click( function( event ) {
	
    process_option_change();
		
		return true;
	});
	
	function process_option_change() {
    
    var deposit_option = 'full'
    var rad_deposit = $( '#pay-deposit' ).attr( 'checked' );
    if ( rad_deposit == 'checked' ) {
      deposit_option = 'deposit'; 
    }
    
    // Define action and data
		var data = {
			action: 'calculate_deposit',
			deposit_option: deposit_option // Either 'full' for full amount or 'deposit' for the deposit only
		};

		$.ajax({
			type: 		'POST',
			url: 		  php_data.ajax_url,
			data: 		data,
			success: 	function( code ) {
			  console.log("AJAX WORKING!");
			  console.log(code);
			  cart_total = code;
			  $('body').trigger( 'update_checkout' );
			},
			dataType: 'html'
		});
	}
	
  function apply_deposit() {

    if (cart_total == -1) {
      process_option_change();
    } else {
      var rad_deposit = $( '#pay-deposit' ).prop( 'checked' );
  	
      $('#due-today').remove();
      $('#due-later').remove();
      
      if ( rad_deposit == true ) {
          
        var chosen_shipping_cost = 0;
    		$( 'select.shipping_method, input[name^=shipping_method][type=radio]:checked, input[name^=shipping_method][type=hidden]' ).each( function( index, input ) {
          if (typeof $('label[for='+input.getAttribute('id')+'] span').html() !== 'undefined'){
            chosen_shipping_cost =  Number( $('label[for='+input.getAttribute('id')+'] span').html().replace(/[^0-9\.]+/g,""));            
          }
    		} );            

        /* var due_today = (cart_total / 100.0) * deposit_value; */
        var due_today = parseFloat(deposit_value) + parseFloat(chosen_shipping_cost);
        var due_later = cart_total - due_today + parseFloat(chosen_shipping_cost);
        
      	var table_row1 = '<tr class="due-today" id="due-today"><th>Deposit Payable Today</th><td><strong>' + format_dollar_amount( due_today ) + '</strong></td></tr>';
      	var table_row2 = '<tr class="due-later" id="due-later"><th>Remaining Balance</th><td><strong>' + format_dollar_amount( due_later ) + '</strong></td></tr>';
      	
      	$('#order_review .shop_table tfoot').append( table_row1 );
      	$('#order_review .shop_table tfoot').append( table_row2 );
      
        // Update hidden fields accordingly to be added to order metadata
        $( '#total_order_cost_hidden' ).val( cart_total );
        $( '#deposit_amount_hidden' ).val( due_today );
        $( '#remaining_balance_hidden' ).val( due_later );
        
        // Update deposit total
        $( '.order-total td' ).html( '<strong>' + format_dollar_amount( due_today + due_later ) + '</strong>' );
      }
    }
	}
	
	function format_dollar_amount(due_today) {
  	var result = parseFloat( due_today ).toFixed(2);
  	
  	switch (currency_format){
    	case 'left':
    	  result = currency_sign + result;
    	  break;
      case 'right':
        result = result + currency_sign;
    	  break;
      case 'left_space':
        result = currency_sign + "&nbsp;" + result;
        break;
      case 'right_space':
        result = result + "&nbsp;" + currency_sign;
        break;
  	}
  	
  	return result;
	}
});