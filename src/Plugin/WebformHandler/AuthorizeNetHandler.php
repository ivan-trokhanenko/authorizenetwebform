<?php

namespace Drupal\authorizenetwebform\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformMessageManagerInterface;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

/**
 * Webform submission authorize_net handler.
 *
 * @WebformHandler(
 *   id = "authorize_net",
 *   label = @Translation("Authorize.Net Handler"),
 *   category = @Translation("External"),
 *   description = @Translation("Posts webform submissions to Authorize.Net."),
 *   cardinality =
 *   \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results =
 *   \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission =
 *   \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class AuthorizeNetHandler extends WebformHandlerBase {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  /**
   * The webform message manager.
   *
   * @var \Drupal\webform\WebformMessageManagerInterface
   */
  protected $messageManager;

  /**
   * A webform element plugin manager.
   *
   * @var \Drupal\webform\Plugin\WebformElementManagerInterface
   */
  protected $elementManager;

  /**
   * List of unsupported webform submission properties.
   *
   * The below properties will not being included in a remote post.
   *
   * @var array
   */
  protected $unsupportedProperties = [
    'metatag',
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, ModuleHandlerInterface $module_handler, WebformTokenManagerInterface $token_manager, WebformMessageManagerInterface $message_manager, WebformElementManagerInterface $element_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator);
    $this->moduleHandler = $module_handler;
    $this->tokenManager = $token_manager;
    $this->messageManager = $message_manager;
    $this->elementManager = $element_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('module_handler'),
      $container->get('webform.token_manager'),
      $container->get('webform.message_manager'),
      $container->get('plugin.manager.webform.element')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $configuration = $this->getConfiguration();
    $settings = $configuration['settings'];

    $settings['payment_status'] = $this->configuration['payment_status'];
    $settings['x_relay_url'] = $this->getEndpoint();
    
    return [
      '#settings' => $settings,
    ] + parent::getSummary();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'x_login' => '',
      'auth_key' => '',
      'payment_status' => 'live',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['x_login'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Login ID'),
      '#description' => $this->t('Enter your Authorize.Net login id.'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['x_login'],
      '#weigth' => 0,
    ];
    $form['auth_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transaction Key'),
      '#description' => $this->t('Enter your Authorize.Net auth key.'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['auth_key'],
      '#weigth' => 1,
    ];
    $form['payment_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#description' => $this->t('The payment status'),
      '#options' => ['test' => $this->t('Test'), 'live' => $this->t('Live')],
      '#default_value' => $this->configuration['payment_status'],
      '#weigth' => 2,
    ];
    $this->elementTokenValidate($form);
    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);

    // Cast debug.
    $this->configuration['debug'] = (bool) $this->configuration['debug'];
  }
  
  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $form['#attached']['library'][] = 'authorizenetwebform/offsite_redirect';
  }
  
  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $webform_state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();
    $this->authorizeNetPost($webform_state, $webform_submission);
  }

  /**
   * Execute a remote post.
   *
   * @param string $webform_state
   *   The state of the webform submission.
   *   Either STATE_NEW, STATE_DRAFT, STATE_COMPLETED, STATE_UPDATED, or
   *   STATE_CONVERTED depending on the last save operation performed.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   */
  protected function authorizeNetPost($webform_state, WebformSubmissionInterface $webform_submission) {
    if ($webform_state == 'completed') {
      $data = $webform_submission->getData();
      if (!empty($data['x_amount'])) {
        try {
          $payment_page = $this->getAnAcceptPaymentPage($webform_submission);
          $token = $payment_page->getToken();
          
          if (array_key_exists('paid', $data)) {
            update_paid($webform_submission->id(), payment_status('pending'));
          }
          
          $rendered_message = Markup::create('<div class="checkout-help">' . $this->t('Please wait while you are redirected to the payment server. If nothing happens within 10 seconds, please click on the button below.') . '</div>
          <form method="post" class="authorizenetwebform-payment-redirect-form" action="' . $this->getEndpoint() . '" id="formAuthorizeNetTestPage" name="formAuthorizeNetTestPage">
          <input type="hidden" name="token" value="' . $token. '" /><button id="btnContinue">' . $this->t('Continue to Authorize.Net to Payment Page').'</button>
          </form>');
          
          $this->messenger()->addMessage($rendered_message);
        }
        catch (\Exception $e) {
          watchdog_exception('authorizenetwebform', $e);
        }
      }
    }
  }

  /**
   * Provides endpoint link.
   *
   * @return string
   */
  public function getEndpoint() {
    if ($this->configuration['payment_status'] == 'live') {
      return 'https://secure.authorize.net/payment/payment';
    }
    else {
      return 'https://test.authorize.net/payment/payment';
    }
  }

  /**
   * Provides hidden parameters for Authorize.Net submission.
   *
   * @return array
   */
  private function getHiddenParams($amount, $currency = 'USD') {
    $configuration = $this->configuration;

    $params['x_login'] = $configuration['x_login'];
    $params['x_fp_sequence'] = $this->getSequence();
    $params['x_fp_timestamp'] = time();
    $params['x_fp_hash'] = $this->getFingerprint($configuration['auth_key'], $configuration['x_login'], $params['x_fp_sequence'], $params['x_fp_timestamp'], $amount, $currency);
    $params['x_version'] = '3.1';
    $params['x_type'] = 'AUTH_CAPTURE';
    $params['x_relay_response'] = 'true';
    $params['x_show_form'] = 'PAYMENT_FORM';
    if ($configuration['payment_status'] != 'live') {
      $params['x_test_request'] = 'TRUE';
    }

    return $params;
  }

  /**
   * Provides x_fp_hash field value.
   *
   * @param string $x_tran_key
   * @param string $x_login
   * @param string $sequence
   * @param string $tstamp
   * @param $amount
   * @param string $currency
   *
   * @return string
   */
  private function getFingerprint($x_tran_key, $x_login, $sequence, $tstamp, $amount, $currency) {
    return $this->hMac($x_tran_key, $x_login . "^" . $sequence . "^" . $tstamp . "^" . $amount . "^" . $currency);
  }

  /**
   * Provides random number for security and better randomness.
   *
   * @return int
   */
  private function getSequence() {
    srand(time());
    return rand(1, 1000);
  }

  /**
   * Compute HMAC-MD5 uses PHP mhash extension.
   *
   * @param string $key
   * @param string $data
   *
   * @return string
   */
  private function hMac($key, $data) {
    return (bin2hex(mhash(MHASH_MD5, $data, $key)));
  }
  
  /**
   * Provides payment information.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   *
   * @return AnetAPI\AnetApiResponseType
   */
  function getAnAcceptPaymentPage(WebformSubmissionInterface $webform_submission) {
    // Get handler settings.
    $configuration = $this->configuration;

    // Get webform submission values.
    $data = $webform_submission->getData();

    /* Create a merchantAuthenticationType object with authentication details
       retrieved from the constants file */
    $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
    $merchantAuthentication->setName($configuration['x_login']);
    $merchantAuthentication->setTransactionKey($configuration['auth_key']);

    // Set the transaction's refId for webform_submission entity.
    $refId = 'ref' . time();
    if (array_key_exists('transaction_reference', $data)) {
      update_transaction_reference($webform_submission->id(), $refId);
    }

    // Create a transaction.
    $transactionRequestType = new AnetAPI\TransactionRequestType();
    $transactionRequestType->setTransactionType("authCaptureTransaction");
    $transactionRequestType->setAmount("35");

    // Set hosted form options.
    $setting1 = new AnetAPI\SettingType();
    $setting1->setSettingName("hostedPaymentButtonOptions");
    $setting1->setSettingValue(json_encode(['text' => 'Pay']));

    $setting2 = new AnetAPI\SettingType();
    $setting2->setSettingName("hostedPaymentOrderOptions");
    $setting2->setSettingValue(json_encode(['show' => FALSE]));

    $setting3 = new AnetAPI\SettingType();
    $setting3->setSettingName("hostedPaymentReturnOptions");
    
    $url_settings = [
      'url' => Url::fromRoute('authorizenetwebform.validation', ['sid' => $webform_submission->id()], ['absolute' => TRUE, 'query' => ['tid' => $refId]])->toString(),
      'cancelUrl' => $this->getWebform()->toUrl('canonical', ['absolute' => TRUE, 'query' => ['cancel' => 'true']])->toString(),
      'showReceipt' => TRUE,
    ];
    $setting3->setSettingValue(json_encode($url_settings));

    // Build transaction request.
    $request = new AnetAPI\GetHostedPaymentPageRequest();
    $request->setMerchantAuthentication($merchantAuthentication);
    $request->setRefId($refId);
    $request->setTransactionRequest($transactionRequestType);
    $request->addToHostedPaymentSettings($setting1);
    $request->addToHostedPaymentSettings($setting2);
    $request->addToHostedPaymentSettings($setting3);

    // Customer info.
    $customer = new AnetAPI\CustomerDataType();
    $customer->setEmail($data['x_email']);

    // Bill To.
    $billto = new AnetAPI\CustomerAddressType();
    $billto->setFirstName($data['x_first_name']);
    $billto->setLastName($data['x_last_name']);
//    $billto->setCompany("Souveniropolis");
//    $billto->setAddress("14 Main Street");
    $billto->setCity($data['x_city']);
    $billto->setState($data['x_state']);
    $billto->setZip($data['x_zip']);
    $billto->setCountry($data['x_country']);

    $transactionRequestType->setCustomer($customer);
    $transactionRequestType->setBillTo($billto);

    // Execute request.
    $controller = new AnetController\GetHostedPaymentPageController($request);
    if ($this->configuration['payment_status'] != 'live') {
      $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);
    }
    else {
      $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::PRODUCTION);
    }

    if (($response != null) && ($response->getMessages()->getResultCode() == "Ok")) {
//      echo $response->getToken()."\n";
    }
    else {
      $logger = \Drupal::logger('authorizenetwebform');
      $logger->error("ERROR :  Failed to get hosted payment page token for @id submission", ['@id' => $webform_submission->id()]);
      $errorMessages = $response->getMessages()->getMessage();
      $logger->error("RESPONSE :  @code - @text", ['@code' => $errorMessages[0]->getCode(), '@text' => $errorMessages[0]->getText()]);
    }
    return $response;
  }

}
