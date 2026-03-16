<?php

namespace Drupal\gmail_importer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Orchestrates the full Gmail → Claude → Drupal import pipeline.
 */
class ImportService {

  protected GmailService $gmail;
  protected ClaudeService $claude;
  protected DrupalNodeService $nodeService;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected MessengerInterface $messenger;

  public function __construct(
    GmailService $gmail,
    ClaudeService $claude,
    DrupalNodeService $node_service,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger
  ) {
    $this->gmail         = $gmail;
    $this->claude        = $claude;
    $this->nodeService   = $node_service;
    $this->loggerFactory = $logger_factory;
    $this->messenger     = $messenger;
  }

  /**
   * Run the full import. Returns ['saved' => int, 'skipped' => int, 'log' => string[]].
   */
  public function run(): array {
    $log     = [];
    $saved   = 0;
    $skipped = 0;

    $log[] = 'Fetching emails from Gmail…';
    $messages = $this->gmail->fetchMessageIds();

    if (empty($messages)) {
      $log[] = 'No emails found for the configured time window.';
      return ['saved' => 0, 'skipped' => 0, 'log' => $log];
    }

    $log[] = sprintf('Found %d email(s) to scan.', count($messages));
// Limit emails per run to avoid rate limiting.
$messages = array_slice($messages, 0, 5);
$log[] = sprintf('Processing up to %d email(s) this run.', count($messages));
    foreach ($messages as $msg_ref) {
      try {
        $msg_data = $this->gmail->fetchMessage($msg_ref['id']);
        if (!$msg_data) {
          $log[] = '  ⚠ Could not fetch message ' . $msg_ref['id'];
          $skipped++;
          continue;
        }

        $headers  = $msg_data['payload']['headers'] ?? [];
        $subject  = $this->gmail->extractHeader($headers, 'Subject') ?: '(No Subject)';
        $sender   = $this->gmail->extractHeader($headers, 'From')    ?: '(Unknown)';
        $date_str = $this->gmail->extractHeader($headers, 'Date')    ?: date('r');
        $body     = $this->gmail->extractBody($msg_data['payload']);
        $gmail_id = $msg_ref['id'];

        $log[] = sprintf('📧 %s | From: %s', substr($subject, 0, 70), $sender);

        // Duplicate check.
        if ($this->nodeService->alreadyExists($gmail_id)) {
          $log[] = '   ✓ Already saved – skipping.';
          $skipped++;
          continue;
        }

        // Job filter.
        $log[] = '   Checking if job-related…';
        if (!$this->claude->isJobRelated($subject, $body, $sender)) {
          $log[] = '   ✗ Not job-related – skipped.';
          $skipped++;
          usleep(500000); // wait 0.5 seconds between calls
          continue;
        }
        usleep(500000); // wait 0.5 seconds after each Claude call

        $log[] = '   ✓ Job-related! Checking for immediate action…';
        $immediate = $this->claude->requiresImmediateAction($subject, $body);

        $ai_summary = '';
        if ($immediate) {
          $log[]      = '   ⚡ Immediate action required – generating summary…';
          $ai_summary = $this->claude->processEmail($subject, $body, $sender, $date_str);
          $log[]      = '   Summary: ' . substr($ai_summary, 0, 120) . '…';
        }
        else {
          $log[] = '   No immediate action needed.';
        }

        $node = $this->nodeService->createNode($subject, $body, $ai_summary, $sender, $date_str, $gmail_id, $immediate);
        if ($node) {
          $log[] = '   ✅ Saved as node #' . $node->id();
          $saved++;
        }
        else {
          $log[] = '   ❌ Failed to save node.';
        }
      }
      catch (\Exception $e) {
        $log[] = '   ❌ Error: ' . $e->getMessage();
        $this->loggerFactory->get('gmail_importer')->error($e->getMessage());
      }
    }

    $log[] = sprintf('Done. Saved: %d | Skipped: %d', $saved, $skipped);
    return ['saved' => $saved, 'skipped' => $skipped, 'log' => $log];
  }

}
