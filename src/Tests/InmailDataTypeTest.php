<?php
/**
 * @file
 * Contains \Drupal\inmail\Tests\InmailDataTypeTest.
 */

namespace Drupal\inmail\Tests;

use Drupal\inmail\TypedData\EmailParticipantDefinition;
use Drupal\simpletest\KernelTestBase;

/**
 * Tests inmail datatypes.
 *
 * @group inmail
 */
class InmailDataTypeTest extends KernelTestBase {
  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['inmail'];

  /**
   * Tests the inmail_email_participant datatype.
   */
  public function testEmailParticipant() {
    $typed_data_manager = \Drupal::typedDataManager();

    // Test the definition create method.
    $data_definition = EmailParticipantDefinition::create();
    $this->assertEqual($data_definition, $typed_data_manager->createDataDefinition('inmail_email_participant'));

    // Access the properties.
    /** @var \Drupal\inmail\Plugin\DataType\EmailParticipant $data */
    $data = $typed_data_manager->create($data_definition, [
      'name' => 'Alice',
      'address' => 'alice@example.com',
    ]);
    $this->assertEqual('Alice', $data->get('name')->getValue());
    $this->assertEqual('alice@example.com', $data->get('address')->getValue());

    // Address is required.
    $violations = $typed_data_manager->create($data_definition, ['name' => 'Alice'])->validate();
    $this->assertEqual(1, $violations->count());
    $this->assertEqual('address', $violations->get(0)->getPropertyPath());
    $this->assertEqual('This value should not be null.', $violations->get(0)->getMessage());

    // Name is not required.
    $violations = $typed_data_manager->create($data_definition, ['address' => 'alice@example.com'])->validate();
    $this->assertEqual(0, $violations->count());

    // Address should have valid format.
    $violations = $typed_data_manager->create($data_definition, ['address' => 'alice'])->validate();
    $this->assertEqual(1, $violations->count());
    $this->assertEqual('address', $violations->get(0)->getPropertyPath());
    $this->assertEqual('This value is not a valid email address.', $violations->get(0)->getMessage());
  }
}