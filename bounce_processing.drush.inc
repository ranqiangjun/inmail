<?php
/**
 * @file
 * Drush commands for the Bounce Processing module.
 */

/**
 * Implements hook_drush_commands().
 */
function bounce_processing_drush_command() {
  $items = array();

  $items['bounce-process'] = array(
    'description' => 'Process a mail message provided through STDIN, or one or more mail messages by filename.',
    'arguments' => array(
      'file [file2 ...]' => 'One or more file names to parse as messages. If no filenames are given, a message is read from STDIN.',
    ),
    'examples' => array(
      'cat 1.eml | drush bounce-process' => 'Process a message through STDIN',
      'drush bounce-process 1.eml 2.eml 3.eml' => 'Process each of the messages contained by the three files',
    ),
    'drupal dependencies' => array('bounce_processing'),
  );

  $items['bounce-services'] = array(
    'description' => 'Tell which service classes are being used for analysis and handling.',
    'drupal dependencies' => array('bounce_processing'),
  );

  return $items;
}

/**
 * Drush command implementation for processing a mail message.
 */
function drush_bounce_processing_bounce_process() {
  if (\Drupal::moduleHandler()->moduleExists('composer_manager')) {
    \Drupal::service('composer_manager.manager')->registerAutolaoder();
  }

  $args = func_get_args();

  if (count($args) > 0) {
    $raws = array();
    foreach ($args as $filename) {
      $raws[] = file_get_contents($filename[0] == '/' ? $filename : __DIR__ . '/' . $filename);
    }
    \Drupal::service('bounce.processor')->processMultiple($raws);
  }
  else {
    $raw = _bounce_processing_get_piped_input();
    \Drupal::service('bounce.processor')->process($raw);
  }
}

/**
 * Output the class names of current analysis and handling services.
 */
function drush_bounce_processing_bounce_services() {
  if (\Drupal::moduleHandler()->moduleExists('composer_manager')) {
    \Drupal::service('composer_manager.manager')->registerAutolaoder();
  }

  $processor = \Drupal::service('bounce.processor');

  if ($analyzers = $processor->getAnalyzers()) {
    foreach ($analyzers as $class) {
      echo "Analyzer: $class\n";
    }
  }
  else {
    echo "No analyzers registered.\n";
  }

  if ($handlers = $processor->getHandlers()) {
    foreach ($handlers as $class) {
      echo "Handler: $class\n";
    }
  }
  else {
    echo "No handlers registered.\n";
  }
}


/**
 * Check for piped input.
 *
 * @return string
 *   Data piped to Drush.
 */
function _bounce_processing_get_piped_input() {
  static $data = NULL;
  if (is_null($data)) {
    $data = '';
    while ($input = fread(STDIN, 1024)) {
      $data .= $input;
    }
  }
  return $data;
}