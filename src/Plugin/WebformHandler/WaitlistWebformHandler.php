<?php

namespace Drupal\webform_waitlist\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform waitlist handler.
 *
 * @WebformHandler(
 *   id = "waitlisthandler",
 *   label = @Translation("Waitlist"),
 *   category = @Translation("Waitlist"),
 *   description = @Translation("Waitlist webform submission handler."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
class WaitlistWebformHandler extends WebformHandlerBase {

  /**
   * The token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  /**
   * Webform submission storage.
   *
   * @var \Drupal\webform\WebformSubmissionStorageInterface
   */
  protected $submissionStorage;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, WebformTokenManagerInterface $token_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator);
    $this->tokenManager = $token_manager;
    $this->submissionStorage = $entity_type_manager->getStorage('webform_submission');
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
      $container->get('webform.token_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Custom settings.
    $custom_settings = $this->configuration;
    unset($custom_settings['debug']);
    $custom_settings = array_diff_key($custom_settings, $this->defaultConfiguration());
    $form['custom_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom settings'),
      '#open' => TRUE,
    ];
    $form['custom_settings']['custom'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Custom settings (YAML)'),
      '#description' => $this->t('Enter the setting name and value as YAML.'),
      '#default_value' => $custom_settings,
      // Must set #parents because custom is not a configuration value.
      // @see \Drupal\webform\Plugin\WebformHandler\SettingsWebformHandler::submitConfigurationForm
      '#parents' => ['settings', 'custom'],
    ];
    $this->elementTokenValidate($form);

    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {

    $this->configuration = $this->defaultConfiguration();

    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);

    // Remove all empty strings from preview and confirmation settings.
    $this->configuration = array_filter($this->configuration);

    // Append custom settings to configuration.
    $this->configuration += $form_state->getValue('custom');

  }

  /**
   * {@inheritdoc}
   */
  public function overrideSettings(array &$settings, WebformSubmissionInterface $webform_submission) {

    $waitlist_enable = $this->configuration['waitlist'];
    $waitlist_enable = $this->replaceTokens($waitlist_enable, $this->getWebformSubmission());
    $waitlist_notice = $this->configuration['waitlist_notice'];
    $waitlist_notice = $this->replaceTokens($waitlist_notice, $this->getWebformSubmission());

    if ($waitlist_enable == 'On') {
      // Clear page cache in case manual changes have been made.
      \Drupal::service('page_cache_kill_switch')->trigger();
      // Check for group content.
      if ($group_content = \Drupal::routeMatch()->getParameter('group_content')) {
        $source_entity = $group_content;
      }
      else {
        $source_entity = $webform_submission->getSourceEntity();
      }
      if (!is_null($source_entity)) {
        $entity_id = $source_entity->id();
        $number_waitlisted = $this->checkTotalWaitlisted($entity_id);
        $number_not_waitlisted = $this->checkTotalNotWaitlisted($entity_id);
        $waitlist_threshold = $this->configuration['waitlist_threshold'];
        $waitlist_threshold = $this->replaceTokens($waitlist_threshold, $this->getWebformSubmission());
        $entity_limit_total = $settings['entity_limit_total'];
        $waitlist_factor = $entity_limit_total + $waitlist_threshold;
        $settings['entity_limit_total'] = $waitlist_factor;

        // Account for if the user has manually set waitlisted submissions.
        // Allow for submissions as long as the number that are not on a
        // waitlist is less than the max submissions allowed.
        if ((($number_not_waitlisted + $number_waitlisted) >= $entity_limit_total) && ($number_not_waitlisted < $entity_limit_total)) {
          $waitlist_factor = $number_not_waitlisted + $number_waitlisted + $waitlist_threshold;
          $settings['entity_limit_total'] = $waitlist_factor;
        }
        // Enable the user-set waitlist message if the
        // submission was placed on a waitlist.
        if (($number_waitlisted < $waitlist_threshold) && ($number_not_waitlisted >= ($entity_limit_total))) {
          $settings['confirmation_message'] = $waitlist_notice;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {

    $waitlist_notice = $this->configuration['waitlist_notice'];
    $waitlist_notice = $this->replaceTokens($waitlist_notice, $this->getWebformSubmission());
    $form['elements']['waitlist_message']['#access'] = FALSE;
    $webform = $webform_submission->getWebform();
    $waitlist_threshold = $this->configuration['waitlist_threshold'];
    $waitlist_threshold = $this->replaceTokens($waitlist_threshold, $this->getWebformSubmission());
    $entity_limit_total = $webform->getSetting('entity_limit_total');

    // Check if entity source is group content.
    if ($group_content = \Drupal::routeMatch()->getParameter('group_content')) {
      $source_entity = $group_content;
    }
    else {
      $source_entity = $webform_submission->getSourceEntity();
    }

    if (!is_null($source_entity)) {
      $entity_id = $source_entity->id();
      $number_waitlisted = $this->checkTotalWaitlisted($entity_id);
      $number_not_waitlisted = $this->checkTotalNotWaitlisted($entity_id);

      // Enable the waitlist warning message element
      // if the submission will be placed on a waitlist.
      if (($number_waitlisted < $waitlist_threshold) && ($number_not_waitlisted >= ($entity_limit_total - $waitlist_threshold)) && (($number_waitlisted + $number_not_waitlisted) < $entity_limit_total)) {
        $form['elements']['waitlist_message']['#access'] = TRUE;
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $webform = $webform_submission->getWebform();
    $waitlist_threshold = $this->configuration['waitlist_threshold'];
    $waitlist_threshold = $this->replaceTokens($waitlist_threshold, $this->getWebformSubmission());
    $entity_limit_total = $webform->getSetting('entity_limit_total');

    if ($group_content = \Drupal::routeMatch()->getParameter('group_content')) {
      $source_entity = $group_content;
    }
    else {
      $source_entity = $webform_submission->getSourceEntity();
    }

    if (!is_null($source_entity)) {
      $entity_id = $source_entity->id();
      $number_not_waitlisted = $this->checkTotalNotWaitlisted($entity_id);
      // $number_waitlisted = $this->checkTotalWaitlisted($entity_id);
      $waitlist_enable = $this->configuration['waitlist'];
      $waitlist_enable = $this->replaceTokens($waitlist_enable, $this->getWebformSubmission());

      // Set submission as waitlisted.
      if ($waitlist_enable == 'On') {
        if ($number_not_waitlisted > ($entity_limit_total - $waitlist_threshold)) {
          $sid = $webform_submission->id();
          \Drupal::database()->update('webform_submission')
            ->condition('sid', $sid)
            ->fields(['is_waitlist' => 1])
            ->execute();
          $this->debug(__FUNCTION__, $update ? 'update' : 'insert');
        }
      }
    }

  }

  /**
   * Check webform submission total limits.
   *
   * @return bool
   *   TRUE if webform submission total limit have been met.
   */
  protected function checkTotalWaitlisted($entity_id) {
    // Returns how many submissions are set on a waitlist.
    $query = \Drupal::database()->select('webform_submission', 'ws')
      ->condition('is_waitlist', '1')
      ->condition('entity_id', $entity_id);
    $number = $query->countQuery()->execute()->fetchField();

    return $number;
  }

  /**
   * Check webform submission total limits.
   *
   * @return bool
   *   TRUE if webform submission total limit have been met.
   */
  protected function checkTotalNotWaitlisted($entity_id) {
    // Returns how many submissions are not on a waitlist.
    $query = \Drupal::database()->select('webform_submission', 'ws')
      ->condition('is_waitlist', '0')
      ->condition('entity_id', $entity_id);
    $number = $query->countQuery()->execute()->fetchField();

    return $number;
  }

  /**
   * Display the invoked plugin method to end user.
   *
   * @param string $method_name
   *   The invoked method name.
   * @param string $context1
   *   Additional parameter passed to the invoked method name.
   */
  protected function debug($method_name, $context1 = NULL) {
    if (!empty($this->configuration['debug'])) {
      $t_args = [
        '@id' => $this->getHandlerId(),
        '@class_name' => get_class($this),
        '@method_name' => $method_name,
        '@context1' => $context1,
      ];
      $this->messenger()->addWarning($this->t('Invoked @id: @class_name:@method_name @context1', $t_args), TRUE);
    }
  }

}
