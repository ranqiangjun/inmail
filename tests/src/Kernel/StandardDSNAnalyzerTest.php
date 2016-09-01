<?php

namespace Drupal\Tests\inmail\Kernel;

use Drupal\Core\Logger\LoggerChannel;
use Drupal\inmail\DefaultAnalyzerResult;
use Drupal\inmail\MIME\Parser;
use Drupal\inmail\Plugin\inmail\Analyzer\StandardDSNAnalyzer;
use Drupal\inmail\ProcessorResult;
use Drupal\KernelTests\KernelTestBase;

/**
 * Unit tests the DSN bounce message analyzer.
 *
 * @coversDefaultClass \Drupal\inmail\Plugin\inmail\Analyzer\StandardDSNAnalyzer
 *
 * @group inmail
 */
class StandardDSNAnalyzerTest extends KernelTestBase {


  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['inmail'];

  /**
   * Returns the raw contents of a given test message file.
   *
   * @param string $filename
   *   The name of the file.
   *
   * @return string
   *   The message content.
   */
  protected function getRaw($filename) {
    $path = __DIR__ . '/../../modules/inmail_test/eml/' . $filename;
    return file_get_contents($path);
  }

  /**
   * Tests the analyze method.
   *
   * @covers ::analyze
   *
   * @dataProvider provideExpectedResults
   */

  public function testAnalyze($filename, $expected_code, $expected_recipient) {
    $message = (new Parser(new LoggerChannel('test')))->parseMessage($this->getRaw($filename));
    // Run the analyzer.
    $analyzer = new StandardDSNAnalyzer(array(), $this->randomMachineName(), array());
    $processor_result = new ProcessorResult();
    $processor_result->ensureAnalyzerResult(DefaultAnalyzerResult::TOPIC, DefaultAnalyzerResult::createFactory());

    $analyzer->analyze($message, $processor_result);
    /** @var \Drupal\inmail\DefaultAnalyzerResult $result */
    $result = $processor_result->ensureAnalyzerResult(DefaultAnalyzerResult::TOPIC, DefaultAnalyzerResult::createFactory());
    $bounce_data = $result->ensureContext('bounce', 'inmail_bounce');
    /** @var \Drupal\inmail\BounceDataDefinition $bounce_context */
    $bounce_context = $result->getContext('bounce');

    // No result object if nothing to report.
    if (!isset($expected_code) && !isset($expected_recipient)) {
      $this->assertFalse(is_null($bounce_context));
    }

    // Test the reported code.
    if (isset($expected_code)) {
      $this->assertEquals($expected_code, $bounce_data->getStatusCode()->getCode());
    }

    // Test the reported target recipient.
    if (isset($expected_recipient)) {
      $this->assertEquals($expected_recipient, $bounce_data->getRecipient());
    }
  }

  /**
   * Provides expected analysis results for test message files.
   */
  public function provideExpectedResults() {
    return [
      ['accessdenied.eml', '5.0.0', 'user@example.org'],
      ['full.eml', '4.2.2', 'user@example.org'],
      ['normal.eml', NULL, NULL],
      ['nouser.eml', '5.1.1', 'user@example.org'],
    ];
  }

}