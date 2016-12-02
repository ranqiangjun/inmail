<?php

namespace Drupal\inmail\Tests;

/**
 * Tests all 'Email display' cases.
 *
 * @group inmail
 * @requires module past_db
 */
class InmailEmailDisplayTest extends InmailWebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'inmail_mailmute',
    'field_ui',
    'past_db',
    'past_testhidden',
    'inmail_test',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Make sure new users are blocked until approved by admin.
    \Drupal::configFactory()->getEditable('user.settings')
      ->set('register', USER_REGISTER_VISITORS_ADMINISTRATIVE_APPROVAL)
      ->save();
    // Enable logging of raw mail messages.
    \Drupal::configFactory()->getEditable('inmail.settings')
      ->set('log_raw_emails', TRUE)
      ->save();
  }

  /**
   * Tests the message Email Display behaviour of the Inmail Message element.
   */
  public function testEmailDisplay() {
    $this->doTestSimpleEmailDisplay();
    // Header field tests.
    $this->doTestMissingToEmailDisplay();
    $this->doTestSameReplyToAsFromDisplay();
    $this->doTestMultipleReplyToDisplay();
    $this->doTestMultipleRecipients();
    $this->doTestNoSubjectDisplay();
    // Body message tests.
    $this->doTestMultipartAlternative();
    $this->doTestUnknownParts();
    $this->doTestHtmlOnlyBodyMessage();
    $this->doTestXssEmailDisplay();
  }

  /**
   * Tests simple email message.
   */
  public function doTestSimpleEmailDisplay() {
    // @todo rename again normal-forwarded.eml to simple-message.eml?
    $raw_multipart = $this->getMessageFileContents('normal-forwarded.eml');
    $this->processRawMessage($raw_multipart);
    $event = $this->getLastEventByMachinename('process');
    $message = $this->parser->parseMessage($raw_multipart);

    // Check if the header fields are properly displayed in 'teaser' view mode.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/teaser');
    $this->assertAddressHeaderField('From', 'arild@masked1.se', 'Arild Matsson');
    $this->assertAddressHeaderField('To', 'inmail_test@example.com', 'Arild Matsson');
    $this->assertAddressHeaderField('CC', 'inmail_other@example.com', 'Someone Else');
    $this->assertNoElementHeaderField('Date', '2014-10-21 20:21:01');
    $this->assertNoElementHeaderField('Received', '2014-10-21 20:21:02');
    $this->assertElementHeaderField('Subject', 'BMH testing sample');
    $this->assertNoLink('Unsubscribe');
    $this->assertRaw('Hey, it would be really bad for a mail handler to classify this as a bounce');
    $this->assertRaw('just because I have no mailbox outside my house.');

    // Check if the header fields are properly displayed in 'full' view mode.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/full');
    // @todo Introduce assert helper for body.
    // Parties involved.
    $this->assertAddressHeaderField('From', 'arild@masked1.se', 'Arild Matsson');
    $this->assertAddressHeaderField('To', 'inmail_test@example.com', 'Arild Matsson');
    $this->assertAddressHeaderField('CC', 'inmail_other@example.com', 'Someone Else');
    $this->assertElementHeaderField('Date', '2014-10-21 20:21:01');
    $this->assertElementHeaderField('Received', '2014-10-21 20:21:02');
    $this->assertElementHeaderField('Subject', 'BMH testing sample');
    // @todo use assertUnsubscribeHeaderField()/assertNoUnsubscribeHeaderField()?
    $this->assertLink('Unsubscribe');

    // @todo separate this (multi)part to another method?
    // Assert message plain-text/HTML parts.
    $this->assertText($message->getPart(0)->getDecodedBody());
    $this->assertText(htmlspecialchars($message->getPlainText()));
    // Script tags are removed for security reasons.
    $this->assertRaw('<div dir="ltr">Hey, it would be really bad for a mail handler to classify this as a bounce just because I have no mailbox outside my house.</div>');
    $this->assertRaw('Hey, it would be really bad for a mail handler to classify this as a bounce<br />');
    $this->assertRaw('just because I have no mailbox outside my house.');
    // @todo add test for unknown parts?
    // Testing the access to past event created by non-inmail module.
    // @see \Drupal\inmail_test\Controller\EmailDisplayController.
    $event = past_event_create('past', 'test1', 'Test log entry');
    $event->save();
    $this->drupalGet('admin/inmail-test/email/' . $event->id());
    // Should throw a NotFoundHttpException.
    $this->assertResponse(404);
    $this->assertText('Page not found');
  }

  /**
   * Tests an email message without the 'To' header field.
   */
  public function doTestMissingToEmailDisplay() {
    // According to RFC 2822, 'To' header field is not strictly necessary.
    $raw_missing_to = $this->getMessageFileContents('/addresses/missing-to-field.eml');
    $this->processRawMessage($raw_missing_to);
    $event = $this->getLastEventByMachinename('process');

    // Check that the raw message is logged.
    $this->assertEqual($event->getArgument('email')->getData(), $raw_missing_to);

    // Assert no 'To' header field is displayed in 'full' view mode.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/full');
    $this->assertNoAddressHeaderField('To');

    // Assert no 'To' header field is displayed in 'teaser' view mode.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/teaser');
    $this->assertNoAddressHeaderField('To');
  }

  /**
   * Tests an email with same 'Reply-To' and 'From' header fields.
   */
  public function doTestSameReplyToAsFromDisplay() {
    $raw_same_reply_to_as_from = $this->getMessageFileContents('/addresses/reply-to-same-as-from.eml');
    $this->processRawMessage($raw_same_reply_to_as_from);
    $event = $this->getLastEventByMachinename('process');

    // Do not display 'Reply-To' in 'full', if it is the same as 'From'.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/full');
    $this->assertAddressHeaderField('From', 'bob@example.com', 'Bob');
    $this->assertNoAddressHeaderField('reply to', 'bob@example.com', 'Bob');

    // Never display 'Reply-To' in 'teaser'.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/teaser');
    $this->assertAddressHeaderField('From', 'bob@example.com', 'Bob');
    $this->assertNoAddressHeaderField('reply to', 'bob@example.com', 'Bob');
  }

  /**
   * Tests an email with multiple 'Reply-To' mailboxes, including an identical.
   */
  public function doTestMultipleReplyToDisplay() {
    $raw_multiple_reply_to = $this->getMessageFileContents('/addresses/reply-to-multiple.eml');
    $this->processRawMessage($raw_multiple_reply_to);
    $event = $this->getLastEventByMachinename('process');

    // Even if one of the 'Reply-To' addresses is identical to 'From', all
    // mailboxes should be visible in 'full' view mode header.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/full');
    $this->assertAddressHeaderField('From', 'bob@example.com', 'Bob');
    $this->assertAddressHeaderField('reply to', 'bob@example.com', 'Bob', 1);
    $this->assertAddressHeaderField('reply to', 'bobby@example.com', 'Bobby', 2);

    // Never display 'Reply-To' in 'teaser'.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/teaser');
    $this->assertAddressHeaderField('From', 'bob@example.com', 'Bob');
    $this->assertNoAddressHeaderField('reply to', 'bob@example.com', 'Bob', 1);
    $this->assertNoAddressHeaderField('reply to', 'bobby@example.com', 'Bobby', 2);
    // Do not display Date in teaser (bug found in #2824195, see comment #14).
    $this->assertNoElementHeaderField('Date');
  }

  /**
   * Tests the proper rendering of an email with multiple recipients.
   */
  public function doTestMultipleRecipients() {
    $raw_multipart = $this->getMessageFileContents('/addresses/multiple-recipients.eml');
    \Drupal::state()->set('inmail.test.success', '');
    $this->processRawMessage($raw_multipart);
    $event = $this->getLastEventByMachinename('process');

    // Assert all recipients are properly displayed in 'full' view mode.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/full');
    $this->assertAddressHeaderField('From', 'inmail_from@example.com', 'Arthur Smith');
    $this->assertAddressHeaderField('reply to', 'inmail_reply_to1@example.com', 'Rachel', 1);
    $this->assertAddressHeaderField('reply to', 'inmail_reply_to2@example.com', 'Ronald', 2);
    $this->assertAddressHeaderField('To', 'inmail_to1@example.com', 'Bonnie', 1);
    $this->assertAddressHeaderField('To', 'inmail_to2@example.com', 'Bob', 2);
    $this->assertAddressHeaderField('CC', 'inmail_cc1@example.com', 'Christine', 1);
    $this->assertAddressHeaderField('CC', 'inmail_cc2@example.com', 'Carl', 2);

    // Assert the recipients are properly displayed in 'teaser' view mode.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/teaser');
    $this->assertAddressHeaderField('From', 'inmail_from@example.com', 'Arthur Smith');
    // Never display 'Reply-To' in 'teaser'.
    $this->assertNoAddressHeaderField('reply to', 'inmail_reply_to1@example.com', 'Rachel', 1);
    $this->assertNoAddressHeaderField('reply to', 'inmail_reply_to2@example.com', 'Ronald', 2);
    $this->assertAddressHeaderField('To', 'inmail_to1@example.com', 'Bonnie', 1);
    $this->assertAddressHeaderField('To', 'inmail_to2@example.com', 'Bob', 2);
    $this->assertAddressHeaderField('CC', 'inmail_cc1@example.com', 'Christine', 1);
    $this->assertAddressHeaderField('CC', 'inmail_cc2@example.com', 'Carl', 2);
  }

  /**
   * Tests an email message without the 'Subject' header field.
   */
  public function doTestNoSubjectDisplay() {
    // According to RFC 2822, 'Subject' header field is not strictly necessary.
    $raw_missing_subject = $this->getMessageFileContents('/simple/missing-subject-field.eml');
    $this->processRawMessage($raw_missing_subject);
    $event = $this->getLastEventByMachinename('process');

    // Check that 'Subject' default empty text is shown for 'full' view mode.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/full');
    $this->assertElementHeaderField('Subject', '(no subject)');

    // Check that 'Subject' default empty text is shown for 'teaser' view mode.
    $this->drupalGet('admin/inmail-test/email/' . $event->id() . '/teaser');
    $this->assertElementHeaderField('Subject', '(no subject)');
  }

  /**
   * Tests proper iteration and rendering of multipart message.
   */
  public function doTestMultipartAlternative() {
    // @todo test the plain/html body of a mail message.
  }

  /**
   * Tests proper iteration and rendering of HTML-only message.
   */
  public function doTestHtmlOnlyBodyMessage() {
    // @todo test the plain/html body of a mail message.
  }

  /**
   * Tests proper iteration and rendering of unknown parts message.
   */
  public function doTestUnknownParts() {
    // @todo test the unknown parts of a mail message.
  }

  /**
   * Tests a XSS case and that its raw mail message is logged.
   */
  public function doTestXssEmailDisplay() {
    // @todo: Move the XSS part into separate email example and call it xss.eml?
    $raw_message = $this->getMessageFileContents('normal-forwarded.eml');
    $raw_message = str_replace('</div>', "<script>alert('xss_attack')</script></div>", $raw_message);

    // In reality the message would be passed to the processor through a drush
    // script or a mail deliverer.
    // Process the raw mail message.
    $this->processRawMessage($raw_message);

    // Check that the raw message is logged.
    $event = $this->getLastEventByMachinename('process');
    $this->assertEqual($event->getArgument('email')->getData(), $raw_message);
  }

}