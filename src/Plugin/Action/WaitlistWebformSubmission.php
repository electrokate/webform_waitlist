<?php

namespace Drupal\webform_waitlist\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Puts a webform submission on a waitlist.
 *
 * @Action(
 *   id = "system.action.webform_submission.make_waitlisted_action",
 *   label = @Translation("Put submission on waitlist"),
 *   type = "webform_submission"
 * )
 */
class WaitlistWebformSubmission extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    /** @var \Drupal\webform\WebformSubmissionInterface $entity */
    $entity->set('is_waitlist', TRUE)->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\webform\WebformSubmissionInterface $object */
    $result = $object->sticky->access('edit', $account, TRUE)
      ->andIf($object->access('update', $account, TRUE));

    return $return_as_object ? $result : $result->isAllowed();
  }

}