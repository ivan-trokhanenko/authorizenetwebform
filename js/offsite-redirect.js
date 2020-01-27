/**
 * @file
 * Defines behaviors for the payment redirect form.
 */
(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   * Attaches the authorizenetPaymentRedirect behavior.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the commercePaymentRedirect behavior.
   */
  Drupal.behaviors.authorizenetPaymentRedirect = {
    attach: function (context) {
      $('.authorizenetwebform-payment-redirect-form', context).submit();
    }
  };

})(jQuery, Drupal, drupalSettings);
