<?php

namespace Drupal\gmail_importer\Batch;

/**
 * Batch callbacks for Gmail import.
 */
class GmailImportBatch {

  /**
   * Process a chunk of 5 emails.
   */
  public static function processChunk(array $messages, array &$context): void {
    $gmail     = \Drupal::service('gmail_importer.gmail');
    $claude    = \Drupal::service('gmail_importer.claude');
    $nodeService = \Drupal::service('gmail_importer.drupal_node');

    if (empty($context['results'])) {
      $context['results'] = ['saved' => 0, 'skipped' => 0, 'log' => []];
    }

    foreach ($messages as $msg_ref) {
      try {
        $msg_data = $gmail->fetchMessage($msg_ref['id']);
        if (!$msg_data) {
          $context['results']['skipped']++;
          $context['results']['log'][] = '⚠ Could not fetch message ' . $msg_ref['id'];
          continue;
        }

        $headers  = $msg_data['payload']['headers'] ?? [];
        $subject  = $gmail->extractHeader($headers, 'Subject') ?: '(No Subject)';
        $sender   = $gmail->extractHeader($headers, 'From')    ?: '(Unknown)';
        $date_str = $gmail->extractHeader($headers, 'Date')    ?: date('r');
        $body     = $gmail->extractBody($msg_data['payload']);
        $gmail_id = $msg_ref['id'];

        $context['message'] = t('Processing: @subject', ['@subject' => substr($subject, 0, 60)]);

        // Duplicate check.
        if ($nodeService->alreadyExists($gmail_id)) {
          $context['results']['skipped']++;
          $context['results']['log'][] = '✓ Already saved – skipping: ' . substr($subject, 0, 60);
          continue;
        }

        // Analyse with Claude.
        $analysis = $claude->analyseEmail($subject, $body, $sender, $date_str);

        if (!$analysis['job_related']) {
          $context['results']['skipped']++;
          $context['results']['log'][] = '✗ Not job-related: ' . substr($subject, 0, 60);
          continue;
        }

        $node = $nodeService->createNode(
          $subject,
          $body,
          $analysis['summary'],
          $sender,
          $date_str,
          $gmail_id,
          $analysis['immediate_action']
        );

        if ($node) {
          $context['results']['saved']++;
          $context['results']['log'][] = '✅ Saved: ' . substr($subject, 0, 60) . ' (#' . $node->id() . ')';
        }
        else {
          $context['results']['log'][] = '❌ Failed to save: ' . substr($subject, 0, 60);
        }

        // Delay to avoid Claude rate limiting.
        usleep(2000000); // 2 seconds
      }
      catch (\Exception $e) {
        $context['results']['log'][] = '❌ Error: ' . $e->getMessage();
        \Drupal::logger('gmail_importer')->error($e->getMessage());
      }
    }
  }

  /**
   * Called when all batches are complete.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();

    if ($success) {
      $messenger->addStatus(t('Import complete! Saved: @saved | Skipped: @skipped', [
        '@saved'   => $results['saved']   ?? 0,
        '@skipped' => $results['skipped'] ?? 0,
      ]));

      // Show log as a collapsible list.
      if (!empty($results['log'])) {
        $log_items = array_map('htmlspecialchars', $results['log']);
        $messenger->addStatus(t('Log:<br><pre>@log</pre>', [
          '@log' => implode("\n", $log_items),
        ]));
      }
    }
    else {
      $messenger->addError(t('Import finished with errors. Check the logs.'));
    }
  }

}