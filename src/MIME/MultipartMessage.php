<?php

namespace Drupal\inmail\MIME;

/**
 * A multipart message.
 *
 * This is the combination of \Drupal\collect\MIME\MultipartEntity and
 * \Drupal\collect\MIME\Message.
 */
class MultipartMessage extends MultipartEntity implements MessageInterface {

  use MessageTrait;

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
   * {@inheritdoc}
   */
  public function getPlainText() {
    $message_parts = $this->getParts();
    foreach ($message_parts as $key => $part) {
      $content_fields = $part->getContentType();
      $content_type = $content_fields['type'] . '/' . $content_fields['subtype'] ;
      $body = $part->getDecodedBody();

      // The first plaintext or HTML part wins.
      // @todo Consider further parts and concatenate bodies?
      if ($content_type == 'text/plain') {
        return $body;
      }
      else if ($content_type == 'text/html') {
        return strip_tags($body);
      }
    }
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getHtml() {
    foreach ($this->getParts() as $key => $part) {
      $content_type = $part->getContentType()['type'] . '/' . $part->getContentType()['subtype'];
      // The first identified HTML part wins.
      if ($content_type == 'text/html') {
        // @todo: Consider further parts.
        return $part->getDecodedBody();
      }
    }
  }

}
