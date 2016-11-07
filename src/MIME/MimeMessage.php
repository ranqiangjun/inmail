<?php

namespace Drupal\inmail\MIME;

/**
 * Models an email message.
 *
 * @ingroup mime
 */
class MimeMessage extends MimeEntity implements MimeMessageInterface {

  use MimeMessageTrait;

  /**
   * {@inheritdoc}
   */
  public function getMessageId() {
    return $this->getHeader()->getFieldBody('Message-Id');
  }

  /**
   * {@inheritdoc}
   */
  public function getSubject() {
    return $this->getHeader()->getFieldBody('Subject');
  }

  /**
   * Check that the message complies to the RFC standard.
   *
   * @return bool
   *   TRUE if message is valid, otherwise FALSE.
   */
  public function validate() {
    $valid = TRUE;
    // RFC 5322 specifies Date and From header fields as only required fields.
    // @See https://tools.ietf.org/html/rfc5322#section-3.6
    foreach (['Date', 'From'] as $field_name) {
      // If the field is absent, set the validation error.
      if (!$this->getHeader()->hasField($field_name)) {
        $this->setValidationError($field_name, "Missing $field_name field.");
        $valid = FALSE;
      }
      // There should be only one occurrence of Date and From fields.
      elseif (($count = count($this->getHeader()->getFieldBodies($field_name))) > 1) {
        $this->setValidationError($field_name, "Only one occurrence of $field_name field is allowed. Found $count.");
        $valid = FALSE;
      }
    }
    return $valid;
  }

  /**
   * {@inheritdoc}
   */
  public function getPlainText() {
    $content_fields = $this->getContentType();
    $content_type = $content_fields['type'] . '/' . $content_fields['subtype'] ;
    if ($content_type == 'text/plain') {
      return $this->getDecodedBody();
    }
    else if ($content_type == 'text/html') {
      return strip_tags($this->getDecodedBody());
    }
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getHtml() {
    $content_type = $this->getContentType()['type'] . '/' . $this->getContentType()['subtype'];
    return $content_type == 'text/html' ? $this->getDecodedBody() : '';
  }

}