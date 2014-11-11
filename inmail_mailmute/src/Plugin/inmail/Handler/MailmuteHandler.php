<?php
/**
 * @file
 * Contains \Drupal\inmail_mailmute\Plugin\inmail\Handler\MailmuteHandler.
 */

namespace Drupal\inmail_mailmute\Plugin\inmail\Handler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\inmail\Message;
use Drupal\inmail\MessageAnalyzer\Result\AnalyzerResultReadableInterface;
use Drupal\inmail\Plugin\inmail\Handler\HandlerBase;
use Drupal\mailmute\SendStateManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reacts to bounce messages by managing the send state of the bouncing address.
 *
 * @MessageHandler(
 *   id = "mailmute",
 *   label = @Translation("Mailmute")
 * )
 */
class MailmuteHandler extends HandlerBase implements ContainerFactoryPluginInterface {

  /**
   * The Mailmute send state manager.
   *
   * @var \Drupal\mailmute\SendStateManagerInterface
   */
  protected $sendstateManager;

  /**
   * The Inmail logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $loggerChannel;

  /**
   * Constructs a new MailmuteHandler.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, SendStateManagerInterface $sendstate_manager, LoggerChannelInterface $logger_channel) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->sendstateManager = $sendstate_manager;
    $this->loggerChannel = $logger_channel;
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition,
      $container->get('plugin.manager.sendstate'),
      $container->get('logger.factory')->get('inmail')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function invoke(Message $message, AnalyzerResultReadableInterface $result) {
    // Only handle bounces.
    if (empty($result) || !$status_code = $result->getBounceStatusCode()) {
      return;
    }
    if ($status_code->isSuccess()) {
      return;
    }

    // Only handle bounces with an identifiable recipient.
    if (!$address = $result->getBounceRecipient()) {
      // @todo Log the message body or place it in a moderation queue.
      $this->loggerChannel->info('Bounce with status %code received but no recipient identified.', ['%code' => $status_code]);
      return;
    }

    // Only handle bounces with an identifiable recipient that we care about.
    if (!$this->sendstateManager->isManaged($address)) {
      $this->loggerChannel->info('Bounce with status %code received but recipient %address is not managed here.', [
        '%code' => $status_code,
        '%address' => $address,
      ]);
      return;
    }

    // Block transition if current state is "Persistent send".
    if ($this->sendstateManager->getState($address)->getPluginId() == 'persistent_send') {
      $this->loggerChannel->info('Send state not transitioned for %address because state was %old_state', [
        '%address' => $address,
        '%old_state' => 'persistent_send',
      ]);
      return;
    }

    // In the case of a "hard bounce", set the send state to a muting state.
    if ($status_code->isPermanentFailure()) {
      $new_state = 'inmail_invalid_address';
      $this->sendstateManager->setState($address, $new_state);
      $this->loggerChannel->info('Bounce with status %code triggered send state transition of %address to %new_state', [
        '%code' => $status_code->getCode(),
        '%address' => $address,
        '%new_state' => $new_state,
      ]);
    }
    else {
      // @todo Handle transient bounces (mailbox full, connection error).
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array(
      'soft_tolerance' => 5,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['soft_tolerance'] = array(
      '#title' => 'Soft bounce tolerance',
      '#type' => 'number',
      '#default_value' => $this->configuration['soft_tolerance'],
      '#description' => $this->t('This defines how many soft bounces may be received from an address before its state is transitioned to "Temporarily unreachable".'),
      '#description_display' => 'after',
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['soft_tolerance'] = $form_state->getValue('soft_tolerance');
  }

}
