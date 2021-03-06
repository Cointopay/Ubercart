<?php

namespace Drupal\uc_cointopay\Plugin\Ubercart\PaymentMethod;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\uc_order\OrderInterface;
use Drupal\uc_payment\OffsitePaymentMethodPluginInterface;
use Drupal\uc_payment\PaymentMethodPluginBase;

/**
 * Defines the Cointopay payment method.
 *
 * @UbercartPaymentMethod(
 *   id = "cointopay",
 *   name = @Translation("Cointopay")
 * )
 */

class Cointopay extends PaymentMethodPluginBase implements OffsitePaymentMethodPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getDisplayLabel($label) {
    $build['#attached']['library'][] = 'uc_cointopay/Cointopay.styles';
    $build['label'] = array(
      '#plain_text' => $label,
      '#suffix' => '<br />',
    );
    $build['image'] = array(
      '#theme' => 'image',
      '#uri' => drupal_get_path('module', 'uc_cointopay') . '/images/2co_logo.jpg',
      '#alt' => $this->t('Cointopay'),
      '#attributes' => array('class' => array('uc-Cointopay-logo')),
    );

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'check' => FALSE,
      'checkout_type' => 'dynamic',
      'demo' => TRUE,
      'language' => 'en',
      'notification_url' => '',
      'secret_word' => 'axf_therightsw',
      'security_code' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['mid'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#description' => $this->t('Your Cointopay Merchant ID.'),
      '#default_value' => $this->configuration['mid'],
      '#size' => 16,
    );
    $form['security_code'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Security Code'),
      '#description' => $this->t('Your Cointopay Security Code.'),
      '#default_value' => $this->configuration['security_code'],
      '#size' => 16,
    );
    $form['secret_word'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Secret word for order verification'),
      '#description' => $this->t('The secret word entered in your Cointopay account Look and Feel settings.'),
      '#default_value' => $this->configuration['secret_word'],
      '#size' => 16,
    );
    $form['language'] = array(
      '#type' => 'select',
      '#title' => $this->t('Language preference'),
      '#description' => $this->t('Adjust language on Cointopay pages.'),
      '#options' => array(
        'en' => $this->t('English'),
        'sp' => $this->t('Spanish'),
      ),
      '#default_value' => $this->configuration['language'],
    );
    $form['notification_url'] = array(
      '#type' => 'url',
      '#title' => $this->t('Instant notification settings URL'),
      '#description' => $this->t('Pass this URL to the <a href=":help_url">instant notification settings</a> parameter in your Cointopay account. This way, any refunds or failed fraud reviews will automatically cancel the Ubercart order.', [':help_url' => Url::fromUri('https://www.Cointopay.com/static/va/documentation/INS/index.html')->toString()]),
      '#default_value' => Url::fromRoute('uc_cointopay.notification', [], ['absolute' => TRUE])->toString(),
      '#attributes' => array('readonly' => 'readonly'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['check'] = $form_state->getValue('check');
    $this->configuration['checkout_type'] = $form_state->getValue('checkout_type');
    $this->configuration['demo'] = $form_state->getValue('demo');
    $this->configuration['language'] = $form_state->getValue('language');
    $this->configuration['notification_url'] = $form_state->getValue('notification_url');
    $this->configuration['secret_word'] = $form_state->getValue('secret_word');
    $this->configuration['security_code'] = $form_state->getValue('security_code');
     $this->configuration['mid'] = $form_state->getValue('mid');
  }

  /**
   * {@inheritdoc}
   */
  public function cartDetails(OrderInterface $order, array $form, FormStateInterface $form_state) {
    $build = array();
    $session = \Drupal::service('session');
    if ($this->configuration['check']) {
      $build['pay_method'] = array(
        '#type' => 'select',
        '#title' => $this->t('Select your payment type:'),
        '#default_value' => $session->get('pay_method') == 'CK' ? 'CK' : 'CC',
        '#options' => array(
          'CC' => $this->t('Credit card'),
          'CK' => $this->t('Online check'),
        ),
      );
      $session->remove('pay_method');
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function cartProcess(OrderInterface $order, array $form, FormStateInterface $form_state) {
    $session = \Drupal::service('session');
    if (NULL != $form_state->getValue(['panes', 'payment', 'details', 'pay_method'])) {
      $session->set('pay_method', $form_state->getValue(['panes', 'payment', 'details', 'pay_method']));
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function cartReviewTitle() {
    if ($this->configuration['check']) {
      return $this->t('Credit card/eCheck');
    }
    else {
      return $this->t('Credit card');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildRedirectForm(array $form, FormStateInterface $form_state, OrderInterface $order = NULL) {
	global $base_url;
    $address = $order->getAddress('billing');
    if ($address->country) {
      $country = \Drupal::service('country_manager')->getCountry($address->country)->getAlpha3();
    }
    else {
      $country = '';
    }

    $data = array(
      'security_code' => $this->configuration['security_code'],
      'mid' => $this->configuration['mid'],
      'mode' => '2CO',
      'card_holder_name' => Unicode::substr($address->first_name . ' ' . $address->last_name, 0, 128),
      'street_address' => Unicode::substr($address->street1, 0, 64),
      'street_address2' => Unicode::substr($address->street2, 0, 64),
      'city' => Unicode::substr($address->city, 0, 64),
      'state' => $address->zone,
      'zip' => Unicode::substr($address->postal_code, 0, 16),
      'country' => $country,
      'email' => Unicode::substr($order->getEmail(), 0, 64),
      'phone' => Unicode::substr($address->phone, 0, 16),
      'purchase_step' => 'payment-method',

      'demo' => $this->configuration['demo'] ? 'Y' : 'N',
      'lang' => $this->configuration['language'],
      'merchant_order_id' => $order->id(),
      'pay_method' => 'CC',
      'x_receipt_link_url' => Url::fromRoute('uc_cointopay.complete', ['cart_id' => \Drupal::service('uc_cart.manager')->get()->getId()], ['absolute' => TRUE])->toString(),

      'total' => uc_currency_format($order->getTotal(), FALSE, FALSE, '.'),
      'currency_code' => $order->getCurrency(),
      'cart_order_id' => $order->id(),
    );

    $i = 0;
    foreach ($order->products as $product) {
      $i++;
      $data['li_' . $i . '_type'] = 'product';
      $data['li_' . $i . '_name'] = $product->title->value; // @todo: HTML escape and limit to 128 chars
      $data['li_' . $i . '_quantity'] = $product->qty->value;
      $data['li_' . $i . '_product_id'] = $product->model->value;
      $data['li_' . $i . '_price'] = uc_currency_format($product->price->value, FALSE, FALSE, '.');
    }
	
    $base_url_parts = parse_url($base_url);
	$host = $base_url_parts['host'];
    $form['#action'] = $base_url_parts['scheme']."://".$host."/cart/cointopay/process/order";//"https://$host.cointopay.com/checkout/purchase";

    foreach ($data as $name => $value) {
      $form[$name] = array('#type' => 'hidden', '#value' => $value);
    }

    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Submit order'),
    );

    return $form;
  }

}
