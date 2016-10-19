<?php

namespace Drupal\inmail\Element;

use Drupal\Core\Render\Element\RenderElement;
use Drupal\inmail\MIME\MultipartEntity;

/**
 * Provides a render element for displaying Inmail Message.
 *
 * If a #download_url url is provided, the element will display attachments
 * with a download link. Otherwise just attachment labels.
 *
 * Properties:
 * - #message: The parsed message object.
 *    An instance of \Drupal\inmail\MIME\MessageInterface.
 * - #view_mode: The view mode ("teaser" or "full").
 * - (optional) #body: Identified mail body.
 *
 * Properties available for MIME messages:
 * - (optional) #attachments: A list of mail attachments. The build array
 *   should follow the structure defined in: inmail_message_build_attachment().
 * - (optional) #unknown: A list of non-identified mail parts.
 * - (optional) #download_url: An URL object with the attachment download route.
 *   the object already needs to point to the specific message and maintain a
 *   parameter named "index" to deal with multipart references.
 *
 * Usage example:
 * @code
 * $build['inmail_message_example'] = [
 *   '#title' => $this->t('Inmail Message Example'),
 *   '#type' => 'inmail_message',
 *   '#message' => $message,
 *   '#view_mode' => 'full',
 *   '#attachments' => $attachments,
 *   '#body' => $body,
 *   '#download_url' => $url,
 * ];
 * @endcode
 *
 * @RenderElement("inmail_message")
 */
class InmailMessage extends RenderElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return ['#theme' => 'inmail_message'];
  }

  /**
   * Returns a filtered array of MIME entities separated by their types.
   *
   * @param \Drupal\inmail\MIME\MultipartEntity $multipart_entity
   *   The multipart entity.
   *
   * @return array
   *   An array of MIME entities separated by the following keys:
   *      - attachments
   *      - inline
   *      - related
   *      - unknown
   */
  public static function filterMessageParts(MultipartEntity $multipart_entity) {
    $elements = [
      'attachments' => [],
      'inline' => [],
      'related' => [],
      'unknown' => [],
    ];

    // Iterate over message parts.
    foreach ($multipart_entity->getParts() as $index => $message_part) {
      // In case the message part is a multipart entity, recurse until we get
      // a non-multipart entity.
      if ($message_part instanceof MultipartEntity) {
        $elements = array_merge($elements, static::filterMessageParts($message_part));
      }
      else {
        // Otherwise, filter the message part based on its type.
        switch ($message_part->getType()) {
          case 'attachment':
            $elements['attachments'][$index] = $message_part;
            break;

          case 'inline':
            // @todo: Add multi-level references for related and inline parts
            //    https://www.drupal.org/node/2819713
            $elements['inline'][$index] = $message_part;
            break;

          // @todo: Handle plain/html (related) message parts.
          default:
            $elements['unknown'][$index] = $message_part;
        }
      }
    }

    return $elements;
  }

}
