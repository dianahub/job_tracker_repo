<?php

namespace Drupal\gmail_importer\Service;

use Drupal\node\Entity\Node;

/**
 * Creates and queries email_record nodes in Drupal.
 */
class DrupalNodeService {

  /**
   * Returns TRUE if a node with the given Gmail message ID already exists.
   */
  public function alreadyExists(string $gmail_id): bool {
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'email_record')
      ->condition('field_gmail_id', $gmail_id)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    return !empty($nids);
  }

  /**
   * Create and save an email_record node.
   *
   * @return \Drupal\node\Entity\Node|null
   */
  public function createNode(
    string $subject,
    string $body,
    string $ai_summary,
    string $sender,
    string $date_str,
    string $gmail_id,
    bool $immediate_action
  ): ?Node {
    try {
      $values = [
        'type'                  => 'email_record',
        'title'                 => substr($subject ?: 'Untitled Email', 0, 255),
        'status'                => 1,
        'field_subject'         => substr($subject, 0, 255),
        'field_sender'          => substr($sender, 0, 255),
        'field_gmail_id'        => $gmail_id,
        'field_immediate_action'=> (int) $immediate_action,
        'field_ai_summary'      => $ai_summary,
        'field_body'            => [
          'value'  => $body,
          'format' => 'full_html',
        ],
      ];

      // Parse and set date field if possible.
      $iso = self::parseDate($date_str);
      if ($iso) {
        $values['field_date'] = $iso;
      }

      $node = Node::create($values);
      $node->save();
      return $node;
    }
    catch (\Exception $e) {
      \Drupal::logger('gmail_importer')->error('Node creation failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  private static function parseDate(string $date_str): ?string {
    try {
      $dt = new \DateTime($date_str);
      $dt->setTimezone(new \DateTimeZone('UTC'));
      return $dt->format('Y-m-d\TH:i:s');
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
