<?php

namespace Drupal\gmail_importer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Wraps Anthropic Claude API calls used for email filtering and summarisation.
 */
class ClaudeService {

  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
  }

  // ── Sanitize input ──────────────────────────────────────────────────────

  private function sanitize(string $text): string {
    // Convert to UTF-8, strip invalid bytes.
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    // Remove non-printable / invalid characters.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    // Final fallback - force valid UTF-8.
    return mb_convert_encoding($text, 'UTF-8', 'ASCII, UTF-8, ISO-8859-1');
  }

  // ── Low-level call ──────────────────────────────────────────────────────

  public function call(string $prompt): ?string {
    $api_key = $this->configFactory->get('gmail_importer.settings')->get('anthropic_api_key');
    if (!$api_key) {
      return NULL;
    }

    try {
      $response = \Drupal::httpClient()->post('https://api.anthropic.com/v1/messages', [
        'headers' => [
          'x-api-key'         => $api_key,
          'anthropic-version' => '2023-06-01',
          'content-type'      => 'application/json',
        ],
        'json' => [
          'model'      => 'claude-haiku-4-5-20251001',
          'max_tokens' => 600,
          'messages'   => [['role' => 'user', 'content' => $prompt]],
        ],
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      return trim($data['content'][0]['text'] ?? '');
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('gmail_importer')->error('Claude API error: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  // ── Single combined API call ────────────────────────────────────────────

  /**
   * Analyse an email in one API call.
   * Returns [
   *   'job_related'      => bool,
   *   'immediate_action' => bool,
   *   'summary'          => string,
   * ]
   */
  public function analyseEmail(string $subject, string $body, string $sender, string $date): array {
    $subject = $this->sanitize($subject);
    $sender  = $this->sanitize($sender);
    $snippet = $this->sanitize(substr($body, 0, 2000));

    $prompt = <<<PROMPT
Analyse this email and answer three questions. Reply in EXACTLY this format with no extra text:

JOB_RELATED: YES or NO
IMMEDIATE_ACTION: YES or NO
SUMMARY: (2-4 sentence summary, or "N/A" if not job-related)

Rules:
- JOB_RELATED is YES for: recruiter outreach, job alerts, application status, interview scheduling, job offers, right-to-represent requests, staffing agency screening calls.
- JOB_RELATED is NO for: networking events, promotions, career advice, webinars, community events.
- IMMEDIATE_ACTION is YES only if the email asks the recipient to reply with a resume, confirm availability, call/email back, or respond ASAP.
- If JOB_RELATED is NO, set IMMEDIATE_ACTION to NO and SUMMARY to N/A.

Sender: {$sender}
Date: {$date}
Subject: {$subject}
Body: {$snippet}
PROMPT;

    $result = $this->call($prompt);

   // Add delay to avoid rate limiting.
usleep(2000000); // 2 seconds

    if ($result === NULL) {
      return $this->keywordFallback($subject, $body);
    }

    $job_related      = str_contains(strtoupper($result), 'JOB_RELATED: YES');
    $immediate_action = str_contains(strtoupper($result), 'IMMEDIATE_ACTION: YES');

    // Extract summary line.
    $summary = 'AI summary unavailable.';
    if (preg_match('/SUMMARY:\s*(.+)/s', $result, $m)) {
      $summary = trim($m[1]);
      if ($summary === 'N/A') {
        $summary = '';
      }
    }

    return [
      'job_related'      => $job_related,
      'immediate_action' => $immediate_action,
      'summary'          => $summary,
    ];
  }

  // ── Keyword fallback (no API call) ──────────────────────────────────────

  private function keywordFallback(string $subject, string $body): array {
    $text = strtolower($subject . ' ' . substr($body, 0, 500));

    $job_keywords = [
      'job offer', 'job alert', 'recruiter', 'hiring', 'interview',
      'your application', 'we reviewed your resume', 'open role',
      'open position', 'salary range', 'new jobs for you',
      'jobs you may like', 'recommended jobs', 'thank you for applying',
      'we received your application', 'right to represent',
    ];

    $action_keywords = [
      'send your resume', 'reply with your resume', 'send me your resume',
      'please reply', 'reach out', 'contact me', 'call me',
      'email me back', 'let me know your interest', 'please send',
      'attach your resume',
    ];

    $job_related = FALSE;
    foreach ($job_keywords as $kw) {
      if (str_contains($text, $kw)) {
        $job_related = TRUE;
        break;
      }
    }

    $immediate_action = FALSE;
    if ($job_related) {
      foreach ($action_keywords as $kw) {
        if (str_contains($text, $kw)) {
          $immediate_action = TRUE;
          break;
        }
      }
    }

    return [
      'job_related'      => $job_related,
      'immediate_action' => $immediate_action,
      'summary'          => '',
    ];
  }

  // ── Legacy methods (kept for compatibility) ─────────────────────────────

  public function isJobRelated(string $subject, string $body, string $sender): bool {
    return $this->analyseEmail($subject, $body, $sender, '')['job_related'];
  }

  public function requiresImmediateAction(string $subject, string $body): bool {
    return $this->analyseEmail($subject, $body, '', '')['immediate_action'];
  }

  public function processEmail(string $subject, string $body, string $sender, string $date): string {
    return $this->analyseEmail($subject, $body, $sender, $date)['summary'];
  }

}