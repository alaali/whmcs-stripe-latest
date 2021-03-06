<?php

define("CLIENTAREA",true);
define("FORCESSL",true);


use WHMCS\Database\Capsule;

if (file_exists('dbconnect.php')) {
	require("dbconnect.php");
} else if (file_exists('init.php')) {
	require("init.php");
} else {
    die('[ERROR] In ccpay.php: include error: Cannot find dbconnect.php or init.php');
}

require("includes/functions.php");
require("includes/clientareafunctions.php");
include("includes/gatewayfunctions.php");
require("modules/gateways/stripe/Stripe.php");

$gateway = getGatewayVariables("stripe");

$gatewaytestmode = $gateway['testmode'];

if ($gatewaytestmode == "on") {
	\Stripe\Stripe::setApiKey($gateway['private_test_key']);
	$pubkey = $gateway['public_test_key'];
} else {
	\Stripe\Stripe::setApiKey($gateway['private_live_key']);
	$pubkey = $gateway['public_live_key'];
}

function send_error($error_type, $error_contents) {
	mail($gateway['problememail'],"Stripe " . $error_type . " Error","Stripe payment processor failed processing a charge due to the following " . $error_type . " error: " . $error_contents);
}

$pagetitle = $_LANG['clientareatitle'] . " - Credit Card Payment Entry";

initialiseClientArea($pagetitle,'',$breadcrumbnav);

$smartyvalues["description"] = $_POST["description"];
$smartyvalues["invoiceid"] = $_POST["invoiceid"];
$smartyvalues["amount"] = $_POST["amount"];
$smartyvalues["total_amount"] = $_POST["total_amount"];
$smartyvalues["planname"] = $_POST["planname"];
$smartyvalues["planid"] = $_POST["planid"];
$smartyvalues["multiple"] = $_POST["multiple"];
$smartyvalues["payfreq"] = $_POST["payfreq"];
$smartyvalues["stripe_pubkey"] = $pubkey;

# Check login status
if ($_SESSION['uid']) {

	if ($_POST['frominvoice'] == "true" || $_POST['ccpay'] == "true") { 

			$result = Capsule::table('tblclients')->select('firstname,lastname,email,address1,address2,state,postcode,city')->where('id', (int)$_SESSION['uid'])->first();

			$firstname = $result->firstname;
			$smartyvalues["firstname"] = $firstname;
			
			$lastname = $result->lastname;
			$smartyvalues["lastname"] = $lastname;
			
			$prepared_name = $firstname . " " . $lastname;
			$smartyvalues["name"] = $prepared_name;
			
	  		$email = $result->email;
	  		$smartyvalues["email"] = $email;
	  		
	  		$address1 = $result->address1;
	  		$smartyvalues["address1"] = $address1;
	  		
	  		$address2 = $result->address2;
	  		$smartyvalues["address2"] = $address2;
	  		
	  		$city = $result->city;
	  		$smartyvalues["city"] = $city;
	  		
	  		$state = $result->state;
	  		$smartyvalues["state"] = $state;
	  		
	  		$zipcode = $result->postcode;
	  		$smartyvalues["zipcode"] = $zipcode;
	
		// Is this a one time payment or is a subscription being set up?
		if ($_POST['payfreq'] == "otp") {
	
			$smartyvalues['explanation'] = "You are about to make a one time credit card payment of <strong>$" . $amount . "</strong>.";
	
			if ($_POST['stripeToken'] != "") {
				
				$token = $_POST['stripeToken'];
				$amount_cents = str_replace(".","",$amount);
				$description = "Invoice #" . $smartyvalues["invoiceid"] . " - " . $email;
		
				try {
				
					$charge = \Stripe\Charge::create(array(
					  "amount" => $amount_cents,
					  "currency" => "usd",
					  "source" => $token,
					  "description" => $description)
					);
		
					if ($charge->card->address_zip_check == "fail") {
						throw new Exception("zip_check_invalid");
					} else if ($charge->card->address_line1_check == "fail") {
						throw new Exception("address_check_invalid");
					} else if ($charge->card->cvc_check == "fail") {
						throw new Exception("cvc_check_invalid");
					}
		
					// Payment has succeeded, no exceptions were thrown or otherwise caught
					$smartyvalues["success"] = true;
		
		
				} catch(\Stripe\Error\Card $e) {
					// Since it's a decline, \Stripe\Error\Card will be caught
					$error = $e->getMessage();
					$smartyvalues["processingerror"] = 'Error: ' . $error . '.';
				
				} catch (\Stripe\Error\RateLimit $e) {
					// Too many requests made to the API too quickly
					send_error("RateLimit",$e);
				} catch (\Stripe\Error\InvalidRequest $e) {
					// Invalid parameters were supplied to Stripe's API
					send_error("InvalidRequest",$e);
				} catch (\Stripe\Error\Authentication $e) {
					// Authentication with Stripe's API failed
					// (maybe you changed API keys recently)
					send_error("authentication",$e);
				} catch (\Stripe\Error\ApiConnection $e) {
					// Network communication with Stripe failed
					send_error("network", $e);
				} catch (\Stripe\Error\Base $e) {
					// Display a very generic error to the user, and maybe send
					// yourself an email
					send_error("generic", $e);
				} catch (Exception $e) {
					// Something else happened, completely unrelated to Stripe
					if ($e->getMessage() == "zip_check_invalid") {
						$smartyvalues["processingerror"] = 'Error: The address information on your account does not match that of the credit card you are trying to use. Please try again or contact us if the problem persists.';
					} else if ($e->getMessage() == "address_check_invalid") {
						$smartyvalues["processingerror"] = 'The address information on your account does not match that of the credit card you are trying to use. Please try again or contact us if the problem persists.';
					} else if ($e->getMessage() == "cvc_check_invalid") {
						$smartyvalues["processingerror"] = 'The credit card information you specified is not valid. Please try again or contact us if the problem persists.';
					} else {
						send_error("unknown", $e);
					}
				  
				}
					
			} // end of if to check if this is a token acceptance for otps
			
		} else { // end if to check if this is a one time payment. else = this IS a otp

			$amount_total = $_POST['total_amount'];
			$amount_subscribe = $_POST['amount'];
			$amount_diff = abs($amount_total - $amount_subscribe);

			if ($multiple == "true") {
				$smartyvalues['explanation'] = "You are about to set up a <strong>$" . $amount_subscribe . "</strong> charge that will automatically bill to your credit card every <strong>month</strong>. You are also going to pay a <strong>one time</strong> charge of <strong>$" . $amount_diff . "</strong>.";
			} else {
				$smartyvalues['explanation'] = "You are about to set up a <strong>$" . $amount_subscribe . "</strong> charge that will automatically bill to your credit card every <strong>month</strong>.";
			}

			if ($_POST['stripeToken'] != "") {
				
				$token = $_POST['stripeToken'];
				$multiple = $_POST['multiple'];
				
				$amount_total_cents = $amount_total * 100;
				$amount_subscribe_cents = $amount_subscribe * 100;
				$amount_diff_cents = $amount_diff * 100;
				
				$message = "Amount Total: " . $amount_total . "<br/>";
				$message .= "Amount Subscribe: " . $amount_subscribe . "<br/>";
				$message .= "Amount Difference (OTP): " . $amount_diff . "<br/>";
				$message .= "Amount Difference (OTP) in Cents: " . $amount_diff_cents . "<br/>";
				
				$ng_plan_name = $_POST['planname'];
				$ng_plan_id = $_POST['planid'];
				$description_otp = "Invoice #" . $smartyvalues["invoiceid"] . " - " . $email .  " - One Time Services";
				$stripe_plan_name = "Invoice #" . $smartyvalues['invoiceid'] . ' - ' . $ng_plan_name . ' - ' . $email;
				
				// Create "custom" plan for this user
				try {
					\Stripe\Plan::create(array(
						"amount" => $amount_subscribe_cents,
						"interval" => "month",
						"name" => $stripe_plan_name,
						"currency" => "usd",
						"id" => $ng_plan_id
					));
				
				
					// Find out if this customer already has a paying item with stripe and if they have a subscription with it
					$current_uid = $_SESSION['uid'];
					
					$q = Capsule::table('tblhosting')->select('subscriptionid')->where('userid', (int)$current_uid)->where('paymentmethod', 'stripe')->where('subscriptionid', '<>', '')->first();
					
					if ($q) {
						$stripe_customer_id = $q->subscriptionid;
					} else {
						$stripe_customer_id = "";
					}

					if ($stripe_customer_id == "") {
						$customer = \Stripe\Customer::create(array( // Sign them up for the requested plan and add the customer id into the subscription id
							"card" => $token,
							"plan" => $ng_plan_id,
							"email" => $email
						));
						$cust_id = $customer->id;
						Capsule::table('tblhosting')->where('id', $ng_plan_id)->update(array('subscriptionid' => $cust_id));
					} else { // Create the customer from scratch
						$c = \Stripe\Customer::retrieve($stripe_customer_id);
						$c->updateSubscription(array("plan" => "basic", "prorate" => false));
					}
					
					if ($customer->card->address_zip_check == "fail") {
						throw new Exception("zip_check_invalid");
					} else if ($charge->card->address_line1_check == "fail") {
						throw new Exception("address_check_invalid");
					} else if ($charge->card->cvc_check == "fail") {
						throw new Exception("cvc_check_invalid");
					}
						
					if ($multiple == "true") { // Bill the customer once for other items they have too
						$charge = \Stripe\Charge::create(array(
							  "amount" => $amount_diff_cents,
							  "currency" => "usd",
							  "customer" => $cust_id,
							  "description" => $description_otp
						));
						
						if ($charge->card->address_zip_check == "fail") {
							throw new Exception("zip_check_invalid");
						} else if ($charge->card->address_line1_check == "fail") {
							throw new Exception("address_check_invalid");
						} else if ($charge->card->cvc_check == "fail") {
							throw new Exception("cvc_check_invalid");
						}
						
					}
					
					// Payment has succeeded, no exceptions were thrown or otherwise caught
					$smartyvalues["success"] = true;
				
				} catch(\Stripe\Error\Card $e) {
					// Since it's a decline, \Stripe\Error\Card will be caught
					$error = $e->getMessage();
					$smartyvalues["processingerror"] = 'Error: ' . $error . '.';
				} catch (\Stripe\Error\RateLimit $e) {
					// Too many requests made to the API too quickly
					send_error("RateLimit",$e);
				} catch (\Stripe\Error\InvalidRequest $e) {
					// Invalid parameters were supplied to Stripe's API
					send_error("InvalidRequest",$e);
				} catch (\Stripe\Error\Authentication $e) {
					// Authentication with Stripe's API failed
					// (maybe you changed API keys recently)
					send_error("authentication",$e);
				} catch (\Stripe\Error\ApiConnection $e) {
					// Network communication with Stripe failed
					send_error("network", $e);
				} catch (\Stripe\Error\Base $e) {
					// Display a very generic error to the user, and maybe send
					// yourself an email
					send_error("generic", $e);
				} catch (Exception $e) {
					// Something else happened, completely unrelated to Stripe
					if ($e->getMessage() == "zip_check_invalid") {
						$smartyvalues["processingerror"] = 'Error: The address information on your account does not match that of the credit card you are trying to use. Please try again or contact us if the problem persists.';
					} else if ($e->getMessage() == "address_check_invalid") {
						$smartyvalues["processingerror"] = 'The address information on your account does not match that of the credit card you are trying to use. Please try again or contact us if the problem persists.';
					} else if ($e->getMessage() == "cvc_check_invalid") {
						$smartyvalues["processingerror"] = 'The credit card information you specified is not valid. Please try again or contact us if the problem persists.';
					} else {
						send_error("unkown", $e);
					}
				  
				}
				
			} // end of if to check if this is a token acceptance for recurs

		}

	} else { // User is logged in but they shouldn't be here (i.e. they weren't here from an invoice)
		
		header("Location: clientarea.php?action=details");
		
	}

} else {

  header("Location: index.php");

}

# Define the template filename to be used without the .tpl extension

$templatefile = "clientareacreditcard-stripe"; 

outputClientArea($templatefile);

?>