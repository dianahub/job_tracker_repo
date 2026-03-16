<?php

namespace Drupal\gmail_importer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\gmail_importer\Service\GmailService;
use Drupal\gmail_importer\Service\ImportService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for OAuth callback and manual import trigger.
 */
class GmailImporterController extends ControllerBase {

  protected GmailService $gmailService;
  protected ImportService $importService;

  public function __construct(GmailService $gmail, ImportService $import) {
    $this->gmailService  = $gmail;
    $this->importService = $import;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('gmail_importer.gmail'),
      $container->get('gmail_importer.import')
    );
  }

  // ── OAuth callback ─────────────────────────────────────────────────────

  public function oauthCallback(Request $request) {
    $code  = $request->query->get('code');
    $error = $request->query->get('error');

    // Step 1 – no code yet → build the OAuth URL and redirect.
    if (!$code && !$error) {
      $auth_url = $this->gmailService->getAuthUrl();
      return new RedirectResponse($auth_url);
    }

    // Step 2 – Google returned an error.
    if ($error) {
      $this->messenger()->addError($this->t('Google OAuth error: @error', ['@error' => $error]));
      return new RedirectResponse(Url::fromRoute('gmail_importer.settings')->toString());
    }

    // Step 3 – exchange the code for tokens.
    try {
      $ok = $this->gmailService->exchangeCode($code);
      if ($ok) {
        $this->messenger()->addStatus($this->t('✅ Gmail authorised successfully! You can now run the import.'));
      }
      else {
        $this->messenger()->addError($this->t('Token exchange failed. Check your Client ID/Secret and try again.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('OAuth error: @msg', ['@msg' => $e->getMessage()]));
    }

    return new RedirectResponse(Url::fromRoute('gmail_importer.settings')->toString());
  }

  // ── Manual run ─────────────────────────────────────────────────────────

 public function run() {
  if (!$this->gmailService->hasToken()) {
    $this->messenger()->addError($this->t('Gmail is not authorised yet. Please authorise first.'));
    return new RedirectResponse(Url::fromRoute('gmail_importer.settings')->toString());
  }

  $messages = $this->gmailService->fetchMessageIds();

  if (empty($messages)) {
    $this->messenger()->addWarning($this->t('No emails found for the configured time window.'));
    return new RedirectResponse(Url::fromRoute('gmail_importer.settings')->toString());
  }

  // Build batch operations - 5 emails per chunk.
  $operations = [];
  foreach (array_chunk($messages, 5) as $chunk) {
    $operations[] = [
      '\Drupal\gmail_importer\Batch\GmailImportBatch::processChunk',
      [$chunk],
    ];
  }

  $batch = [
    'title'            => $this->t('Importing Gmail emails…'),
    'operations'       => $operations,
    'finished'         => '\Drupal\gmail_importer\Batch\GmailImportBatch::finished',
    'progress_message' => $this->t('Processing batch @current of @total…'),
  ];

  batch_set($batch);
  return batch_process(Url::fromRoute('gmail_importer.settings')->toString());
}

}
