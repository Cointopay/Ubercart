<?php

namespace Drupal\uc_cointopay\Controller;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\uc_cart\CartManagerInterface;
use Drupal\uc_order\Entity\Order;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\uc_cointopay\Plugin\Ubercart\PaymentMethod\Cointopay;

/**
 * Controller routines for uc_cointopay.
 */
class CointopayController extends ControllerBase {

  
  protected $cartManager;

  public function __construct(CartManagerInterface $cart_manager) {
    $this->cartManager = $cart_manager;
  }

  public static function create(ContainerInterface $container) {
    // @todo: Also need to inject logger
    return new static(
      $container->get('uc_cart.manager')
    );
  }

  public function sendtcointopay(Request $request)
  {
      //$this->complete($cart_id = 0,$request);
      $order = $request->request;
      $params = array(
        "authentication:1",
        'cache-control: no-cache',
        );

        $ch = curl_init();
        curl_setopt_array($ch, array(
        CURLOPT_URL => 'https://cointopay.com/MerchantAPI?Checkout=true',
        //CURLOPT_USERPWD => $this->apikey,
        CURLOPT_POSTFIELDS => 'SecurityCode=' .$order->get('security_code').'&MerchantID='.$order->get('mid').'&Amount=' . number_format($order->get('total'), 2, '.', '').'&AltCoinID=1&output=json&inputCurrency=USD&CustomerReferenceNr='.$order->get('merchant_order_id').'&transactionconfirmurl='.$this->siteUrl().'/cointopay/order/payment/callback&transactionfailurl='.$this->siteUrl().'/cointopay/order/payment/callback',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $params,
        CURLOPT_USERAGENT => 1,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC
        )
        );
        $redirect = curl_exec($ch);

        
        if($redirect)
        {
            $results = json_decode($redirect);
			if (is_string($results)){
				exit('BadCredentials:'.$results);
		    }
            $response = ['result' => 'success','url' => $results->RedirectURL];
        }
    
        if(!empty($response['url']))
        {
            header("location:".$response['url']." ");
            exit('not redirect');
        }
        exit;
        return false;
  }

public function callback()
{                  
    try
    {   
        $user_id =  \Drupal::currentUser()->id();
        $status = $_GET['status'] ??  '';
        $notEnough = $_GET['notenough'] ??  '';
        $order_id = $_GET['CustomerReferenceNr'];
        $transactionID = $_GET['TransactionID'];
        $notification = '';
        
        // validate order
        if(isset($_GET['ConfirmCode']))
        {
            $config = \Drupal::config('payment_method.settings.cointopay');
            $config = $config->getStorage()->read('uc_payment.method.cointopay');
            
            $data = [
                       'mid' => $config['settings']['mid'] ,
                       'TransactionID' =>  $transactionID ,
                       'ConfirmCode' => $_GET['ConfirmCode']
                   ];
			$transactionData = $this->getTransactiondetail($data);
			if(200 !== $transactionData['status_code']){
				return [
                '#type' => 'markup',
                '#markup' => $transactionData['message']];
                exit;
			}
			else{
				if($transactionData['data']['CustomerReferenceNr'] != $_GET['CustomerReferenceNr']){
					return [
					'#type' => 'markup',
					'#markup' => "Data mismatch! CustomerReferenceNr doesn\'t match"];
					exit;
				}
				elseif($transactionData['data']['TransactionID'] != $_GET['TransactionID']){
					return [
					'#type' => 'markup',
					'#markup' => "Data mismatch! TransactionID doesn\'t match"];
					exit;
				}
				elseif(isset($_GET['AltCoinID']) && $transactionData['data']['AltCoinID'] != $_GET['AltCoinID']){
					return [
					'#type' => 'markup',
					'#markup' => "Data mismatch! AltCoinID doesn\'t match"];
					exit;
				}
				elseif(isset($_GET['MerchantID']) && $transactionData['data']['MerchantID'] != $_GET['MerchantID']){
					return [
					'#type' => 'markup',
					'#markup' => "Data mismatch! MerchantID doesn\'t match"];
					exit;
				}
				elseif(isset($_GET['CoinAddressUsed']) && $transactionData['data']['coinAddress'] != $_GET['CoinAddressUsed']){
					return [
					'#type' => 'markup',
					'#markup' => "Data mismatch! coinAddress doesn\'t match"];
					exit;
				}
				elseif(isset($_GET['SecurityCode']) && $transactionData['data']['SecurityCode'] != $_GET['SecurityCode']){
					return [
					'#type' => 'markup',
					'#markup' => "Data mismatch! SecurityCode doesn\'t match"];
					exit;
				}
				elseif(isset($_GET['inputCurrency']) && $transactionData['data']['inputCurrency'] != $_GET['inputCurrency']){
					return [
					'#type' => 'markup',
					'#markup' => "Data mismatch! inputCurrency doesn\'t match"];
					exit;
				}
				elseif($transactionData['data']['Status'] != $_GET['status']){
					return [
					'#type' => 'markup',
					'#markup' => "Data mismatch! status doesn\'t match. Your order status is ".$transactionData['data']['Status']];
					exit;
				}
				
			}
            $response = $this->validateOrder($data);
           
            if($response->Status !== $_GET['status'])
            {
               return [
                '#type' => 'markup',
                '#markup' => 'We have detected changes in your order status. Your order status is '.$response->Status];
                exit;   
            }
            if($response->CustomerReferenceNr == $_GET['CustomerReferenceNr'])
            {
                //$res = db_query("SELECT count(order_id) FROM uc_orders WHERE order_id ='$order_id' LIMIT 1");
                if($status == 'paid' && $notEnough == 1)
                {
                 // create log file entry............
                    $this->createLogMessage('Customer does not have enough balance to pay');
                    $notification = "You don't have enough balance to pay.";
                }
                else if($status == 'paid' && $notEnough == 0)
                {
                    $Order = Order::load($order_id);
                    $Order->order_status = 'completed';
                    $Order->save();
                    $notification = "Your order has been completed.";
                }
                else if($status=='failed')
                {
                    $Order = Order::load($order_id);
                    $Order->order_status = 'canceled';
                    $Order->save();
                    $notification = "Your order has been failed.";
                }
                else
                {
                    $Order = Order::load($order_id);
                    $Order->order_status = 'canceled';
                    $Order->save();
                    $notification = "Your order has been failed.";
                    
                }
                return [
                '#type' => 'markup',
                '#markup' => $notification];
            }
            else
            {
                return [
                '#type' => 'markup',
                '#markup' => 'We have detected changes in your order. Your order has been halted.'];
                exit;   
            }
        }
        else
        {
             return [
                '#type' => 'markup',
                '#markup' => 'We have detected different order status. Your order has been halted.'];
                exit;   
        }
        
    }
    catch(\Exception $e)
    {
        $this->createLogMessage($e->getMessage());
        exit;
    }
    return '';
}
public function complete($cart_id = 0, Request $request) 
{

    \Drupal::logger('uc_cointopay')->notice('Receiving new order notification for order @order_id.', ['@order_id' => SafeMarkup::checkPlain($request->request->get('merchant_order_id'))]);

    $order = Order::load($request->request->get('merchant_order_id'));
    if (!$order || $order->getStateId() != 'in_checkout') {
      return ['#plain_text' => $this->t('An error has occurred during payment. Please contact us to ensure your order has submitted.')];
    }

    $plugin = \Drupal::service('plugin.manager.uc_payment.method')->createFromOrder($order);

    if ($plugin->getPluginId() != 'Cointopay') {
      throw new AccessDeniedHttpException();
    }

    $configuration = $plugin->getConfiguration();
    $key = $request->request->get('key');
    $order_number = $configuration['demo'] ? 1 : $request->request->get('order_number');
    $valid = md5($configuration['secret_word'] . $request->request->get('sid') . $order_number . $request->request->get('total'));
    
    $address = $order->getAddress('billing');
    $address->street1 = $request->request->get('street_address');
    $address->street2 = $request->request->get('street_address2');
    $address->city = $request->request->get('city');
    $address->postal_code = $request->request->get('zip');
    $address->phone = $request->request->get('phone');
    $address->zone = $request->request->get('state');
    $address->country = $request->request->get('country');
    $order->setAddress('billing', $address);
    $order->save();

    if (Unicode::strtolower($request->request->get('email')) !== Unicode::strtolower($order->getEmail())) {
      uc_order_comment_save($order->id(), 0, $this->t('Customer used a different e-mail address during payment: @email', ['@email' => SafeMarkup::checkPlain($request->request->get('email'))]), 'admin');
    }

    if ($request->request->get('credit_card_processes') == 'Y' && is_numeric($request->request->get('total'))) {
      $comment = $this->t('Paid by @type, cointopay.com order #@order.', ['@type' => $request->request->get('pay_method') == 'CC' ? $this->t('credit card') : $this->t('echeck'), '@order' => SafeMarkup::checkPlain($request->request->get('order_number'))]);
      uc_payment_enter($order->id(), 'cointopay', $request->request->get('total'), 0, NULL, $comment);
    }
    else {
      drupal_set_message($this->t('Your order will be processed as soon as your payment clears at cointopay.com.'));
      uc_order_comment_save($order->id(), 0, $this->t('@type payment is pending approval at cointopay.com.', ['@type' => $request->request->get('pay_method') == 'CC' ? $this->t('Credit card') : $this->t('eCheck')]), 'admin');
    }

    // Add a comment to let sales team know this came in through the site.
    uc_order_comment_save($order->id(), 0, $this->t('Order created through website.'), 'admin');

    $this->cartManager->completeSale($order);
}

/**
* React on INS messages from cointopay.
*
* @param \Symfony\Component\HttpFoundation\Request $request
*   The request of the page.
*/
public function notification(Request $request) 
{
    $values = $request->request;
    \Drupal::logger('uc_cointopay')->notice('Received cointopay notification with following data: @data', ['@data' => print_r($values->all(), TRUE)]);

    if ($values->has('message_type') && $values->has('md5_hash') && $values->has('message_id')) {
      $order_id = $values->get('vendor_order_id');
      $order = Order::load($order_id);
      $plugin = \Drupal::service('plugin.manager.uc_payment.method')->createFromOrder($order);
      $configuration = $plugin->getConfiguration();

      // Validate the hash
      $secret_word = $configuration['secret_word'];
      $sid = $configuration['sid'];
      $twocheckout_order_id = $values->get('sale_id');
      $twocheckout_invoice_id = $values->get('invoice_id');
      $hash = strtoupper(md5($twocheckout_order_id . $sid . $twocheckout_invoice_id . $secret_word));

      if ($hash != $values->get('md5_hash')) {
        \Drupal::logger('uc_cointopay')->notice('cointopay notification #@num had a wrong hash.', ['@num' => $values->get('message_id')]);
        die('Hash Incorrect');
      }

      if ($values->get('message_type') == 'FRAUD_STATUS_CHANGED') {
        switch ($values->get('fraud_status')) {
    // @todo: I think this still needs a lot of work, I don't see anywhere that it
    // validates the INS against an order in the DB then changes order status if the
    // payment was successful, like PayPal IPN does ...
          case 'pass':
            break;

          case 'wait':
            break;

          case 'fail':
            // @todo uc_order_update_status($order_id, uc_order_state_default('canceled'));
            $order->setStatusId('canceled')->save();
            uc_order_comment_save($order_id, 0, $this->t('Order have not passed cointopay fraud review.'));
            die('fraud');
            break;
        }
      }
      elseif ($values->get('message_type') == 'REFUND_ISSUED') {
        // @todo uc_order_update_status($order_id, uc_order_state_default('canceled'));
        $order->setStatusId('canceled')->save();
        uc_order_comment_save($order_id, 0, $this->t('Order have been refunded through cointopay.'));
        die('refund');
      }
    }
    die('ok');
    }
    function  validateOrder($data)
    {
       $params = array(
       "authentication:1",
       'cache-control: no-cache',
       );
       $ch = curl_init();
       curl_setopt_array($ch, array(
       CURLOPT_URL => 'https://app.cointopay.com/v2REAPI?',
       //CURLOPT_USERPWD => $this->apikey,
       CURLOPT_POSTFIELDS => 'MerchantID='.$data['mid'].'&Call=QA&APIKey=_&output=json&TransactionID='.$data['TransactionID'].'&ConfirmCode='.$data['ConfirmCode'],
       CURLOPT_RETURNTRANSFER => true,
       CURLOPT_SSL_VERIFYPEER => false,
       CURLOPT_HTTPHEADER => $params,
       CURLOPT_USERAGENT => 1,
       CURLOPT_HTTPAUTH => CURLAUTH_BASIC
       )
       );
       $response = curl_exec($ch);
       $results = json_decode($response);
       return $results;
       exit(': order not found on cointopay.com');
    }
	function getTransactiondetail($data) {
        $validate = true;

        $merchant_id =  $data['mid'];
        $confirm_code = $data['ConfirmCode'];
        $url = "https://cointopay.com/v2REAPI?Call=Transactiondetail&MerchantID=".$merchant_id."&output=json&ConfirmCode=".$confirm_code."&APIKey=a";
        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 0
        ));
        $result = curl_exec($curl);
        $result = json_decode($result, true);
        return $result;
    }
   function createLogMessage($message)
   {
     \Drupal::logger('uc_cointopay')->notice('Received cointopay notification with following data: @data', ['@data' => $message, TRUE]);
   }


   function siteUrl(){   

// first get http protocol if http or https

$base_url = (isset($_SERVER['HTTPS']) &&

$_SERVER['HTTPS']!='off') ? 'https://' : 'http://';

// get default website root directory

$tmpURL = dirname(__FILE__);

// when use dirname(__FILE__) will return value like this "C:\xampp\htdocs\my_website",

//convert value to http url use string replace, 

// replace any backslashes to slash in this case use chr value "92"

$tmpURL = str_replace(chr(92),'/',$tmpURL);

// now replace any same string in $tmpURL value to null or ''

// and will return value like /localhost/my_website/ or just /my_website/

$tmpURL = str_replace($_SERVER['DOCUMENT_ROOT'],'',$tmpURL);

// delete any slash character in first and last of value

$tmpURL = ltrim($tmpURL,'/');

$tmpURL = rtrim($tmpURL, '/');


// check again if we find any slash string in value then we can assume its local machine

    if (strpos($tmpURL,'/')){

// explode that value and take only first value

       $tmpURL = explode('/',$tmpURL);

       $tmpURL = $tmpURL[0];

      }

// now last steps

// assign protocol in first value

   if ($tmpURL !== $_SERVER['HTTP_HOST'])

// if protocol its http then like this

      $base_url .= $_SERVER['HTTP_HOST'].'/'.$tmpURL.'/';

    else

// else if protocol is https

      $base_url .= $tmpURL.'/';

// give return value

//return $base_url; 
global $base_url;
$base_url_parts = parse_url($base_url);
return  $base_url_parts['scheme']."://".$base_url_parts['host'];
}

}
