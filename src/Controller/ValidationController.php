<?php

namespace Drupal\authorizenetwebform\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Render\Markup;

/**
 * Class ValidationController
 *
 * @package Drupal\authorizenetwebform\Controller
 */
class ValidationController extends ControllerBase {
  
  /**
   * Validates payment by GET request.
   *
   * @param $sid
   *   The webform submission id.
   * @param Request $request
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function validateId($sid, Request $request) {
    /** @var \Drupal\webform\WebformSubmissionInterface $submission */
    $submission = $this->entityTypeManager()->getStorage('webform_submission')->load($sid);
    if ($submission) {
      $data = $submission->getData();
      if (array_key_exists('paid', $data) && array_key_exists('transaction_reference', $data)) {
        $tid = $data['transaction_reference'];
        $refId = $request->query->get('tid');
        if ($tid === $refId) {
          update_paid($sid, payment_status('success'));

          $output = "<p>Congratulations!  You have successfully registered for the 2017 January Jam: MS Plunge for Parcells. Thank you for your contribution to St Vincent's Medical Center Foundation's Dave Parcells MS Fund. Your contribution will help support individuals and their families with unmet needs as they deal with MS.</p>
<p>St. Vincent's Medical Center Foundation is a 501 (c) (3) organization. Your gift is tax deductible to the extent allowed by law. Your credit card statement will reflect your contribution to \"St. Vincent's Foundation.\" On behalf of those you are helping, thank you!</p>
<p>Should you have any difficulty with this transaction, please contact St. Vincent's Medical Center Foundation at (203) 576-5451.</p>";
          $this->messenger()->addMessage(Markup::create($output));
        }
      }
    }
    return $this->redirect('<front>');
  }
  
  /**
   * Validates webhooks from authorize net.
   *
   * @param Request $request
   *
   * @return Response
   */
  public function validateWebhook(Request $request) {
    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    // TODO. Validate authorize.net request.
    if (isset($data['payload']) && !empty($data['payload'])) {
      if (isset($data['payload']['merchantReferenceId'])) {
        $sid = $this->getSubmissionByReference($data['payload']['merchantReferenceId']);
        if ($sid) {
          update_paid($sid, payment_status('complete'));
          return new Response('TRUE', 200);
        }
      }
    }

    return new Response('', 204);
  }

  /**
   * Provides webform submission id by transaction reference id.
   *
   * @param $rid
   *   The transaction reference id.
   * @return integer|null
   */
  public function getSubmissionByReference($rid) {
    $query = \Drupal::database()->select('webform_submission_data', 'wsd');
    $query->addField('wsd', 'sid');
    $query->condition('wsd.name', 'transaction_reference');
    $query->condition('wsd.value', $rid);
    $id = $query->execute()->fetchField();
    return $id;
  }

}
