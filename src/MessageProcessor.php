<?php

namespace Drupal\inmail;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\inmail\Entity\DelivererConfig;
use Drupal\inmail\MIME\ParseException;
use Drupal\inmail\MIME\ParserInterface;
use Drupal\user\Entity\User;

/**
 * Mail message processor using services to analyze and handle messages.
 *
 * @todo Evaluate the analysis algorithms in D7 Bounce and CiviCRM https://www.drupal.org/node/2379845
 *
 * @ingroup processing
 */
class MessageProcessor implements MessageProcessorInterface {

  /**
   * The storage for message analyzer configuration entities.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityStorage
   */
  protected $analyzerStorage;

  /**
   * The plugin manager for analyzer plugins.
   *
   * @var \Drupal\inmail\AnalyzerManagerInterface
   */
  protected $analyzerManager;

  /**
   * The storage for message handler configuration entities.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityStorage
   */
  protected $handlerStorage;

  /**
   * The plugin manager for handler plugins.
   *
   * @var \Drupal\inmail\HandlerManagerInterface
   */
  protected $handlerManager;

  /**
   * The injected logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $loggerChannel;

  /**
   * The injected parser.
   *
   * @var \Drupal\inmail\MIME\ParserInterface
   */
  protected $parser;

  /**
   * The account switcher service.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * Constructs a new message processor.
   */
  public function __construct(EntityManagerInterface $entity_manager, AnalyzerManagerInterface $analyzer_manager, HandlerManagerInterface $handler_manager, LoggerChannelInterface $logger_channel, ParserInterface $parser, AccountSwitcherInterface $account_switcher) {
    $this->analyzerStorage = $entity_manager->getStorage('inmail_analyzer');
    $this->analyzerManager = $analyzer_manager;
    $this->handlerStorage = $entity_manager->getStorage('inmail_handler');
    $this->handlerManager = $handler_manager;
    $this->loggerChannel = $logger_channel;
    $this->parser = $parser;
    $this->accountSwitcher = $account_switcher;
  }

  /**
   * {@inheritdoc}
   */
  public function process($raw, DelivererConfig $deliverer) {
    $event = NULL;
    // Create a log event.
    if (\Drupal::moduleHandler()->moduleExists('past')) {
      $event = past_event_create('inmail', 'process', 'Incoming mail');
      $event->addArgument('deliverer', $deliverer);
    }

    $has_account_changed = FALSE;
    try {
      // Parse message.
      $message = $this->parser->parseMessage($raw);
      // Set event message if parsing the message passed.
      if ($event) {
        $event->setMessage('Incoming mail: ' . $message->getMessageId());
      }

      // Analyze message.
      $result = new ProcessorResult();
      $result->setDeliverer($deliverer);

      /** @var \Drupal\inmail\DefaultAnalyzerResult $default_result */
      $default_result = $result->ensureAnalyzerResult(DefaultAnalyzerResult::TOPIC, DefaultAnalyzerResult::createFactory());
      // Enabled analyzers will be able to update the account.
      $default_result->setAccount(User::getAnonymousUser());

      $analyzer_configs = $this->analyzerStorage->loadMultiple();
      uasort($analyzer_configs, array($this->analyzerStorage->getEntityType()->getClass(), 'sort'));
      foreach ($analyzer_configs as $analyzer_config) {
        /** @var \Drupal\inmail\Entity\AnalyzerConfig $analyzer_config */
        if ($analyzer_config->status() && $analyzer_config->isAvailable()) {
          /** @var \Drupal\inmail\Plugin\inmail\Analyzer\AnalyzerInterface $analyzer */
          $analyzer = $this->analyzerManager->createInstance($analyzer_config->getPluginId(), $analyzer_config->getConfiguration());
          $analyzer->analyze($message, $result);
        }
      }

      foreach ($result->getAnalyzerResults() as $analyzer_result) {
        $event and $event->addArgument(get_class($analyzer_result), $analyzer_result->summarize());
      }

      // Conditionally switch to the account identified by analyzers.
      if ($default_result->isUserAuthenticated()) {
        $this->accountSwitcher->switchTo($default_result->getAccount());
        $has_account_changed = TRUE;
      }

      // Handle message.
      foreach ($this->handlerStorage->loadMultiple() as $handler_config) {
        /** @var \Drupal\inmail\Entity\HandlerConfig $handler_config */
        if ($handler_config->status() && $handler_config->isAvailable()) {
          /** @var \Drupal\inmail\Plugin\inmail\handler\HandlerInterface $handler */
          $handler = $this->handlerManager->createInstance($handler_config->getPluginId(), $handler_config->getConfiguration());
          $handler->invoke($message, $result);
        }
      }

      if ($event) {
        // Dump all log items into a past argument per source.
        foreach ($result->readLog() as $source => $log) {
          $messages = [];
          foreach ($log as $item) {
            // Apply placeholders.
            $messages[] = SafeMarkup::format($item['message'], $item['placeholders']);
          }
          $event->addArgument($source, $messages);
        }
      }
    }
    catch (ParseException $e) {
      // Set event message if parsing the message fails.
      if ($event) {
        $event->setMessage('Incoming mail, parsing failed with error: ' . $e->getMessage());
        $event->addException($e);
      }

      $this->loggerChannel->error('Unable to process message, parser failed with error: ' . $e->getMessage());
    }
    finally {
      if ($has_account_changed) {
        // Switch back to a previous account.
        $this->accountSwitcher->switchBack();
      }

      // Save the log event
      if ($event) {
        $event->save();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function processMultiple(array $messages, DelivererConfig $deliverer) {
    foreach ($messages as $message) {
      $this->process($message, $deliverer);
    }
  }
}
