<?php

namespace Drupal\gmail_importer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;

/**
 * Settings form for Gmail Importer.
 */
class GmailImporterSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['gmail_importer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gmail_importer_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('gmail_importer.settings');

    // ── Status banner ──────────────────────────────────────────────────────
    $token_path = \Drupal::service('file_system')->realpath('private://') . '/gmail_importer/token.json';
    $has_token  = file_exists($token_path);

    if ($has_token) {
      $form['status'] = [
        '#type'       => 'markup',
        '#markup'     => '<div class="messages messages--status">✅ Gmail is <strong>authorized</strong>. Token stored at <code>' . $token_path . '</code></div>',
      ];
    }
    else {
      $form['status'] = [
        '#type'       => 'markup',
        '#markup'     => '<div class="messages messages--warning">⚠️ Gmail is <strong>not yet authorized</strong>. Fill in the credentials below and click <em>Authorize Gmail</em>.</div>',
      ];
    }

    // ── Google / Gmail ──────────────────────────────────────────────────────
    $form['gmail'] = [
      '#type'        => 'details',
      '#title'       => $this->t('Google / Gmail credentials'),
      '#open'        => TRUE,
    ];

    $form['gmail']['gmail_client_id'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('OAuth Client ID'),
      '#default_value' => $config->get('gmail_client_id'),
      '#required'      => TRUE,
      '#description'   => $this->t('From your Google Cloud Console → OAuth 2.0 Client IDs.'),
    ];

    $form['gmail']['gmail_client_secret'] = [
      '#type'          => 'password',
      '#title'         => $this->t('OAuth Client Secret'),
      '#default_value' => $config->get('gmail_client_secret'),
      '#description'   => $this->t('Leave blank to keep the existing secret.'),
      '#attributes'    => ['autocomplete' => 'new-password'],
    ];

    $callback_url = Url::fromRoute('gmail_importer.oauth_callback', [], ['absolute' => TRUE])->toString();
    $form['gmail']['callback_info'] = [
      '#type'   => 'markup',
      '#markup' => '<p>' . $this->t('Add this URL as an <strong>Authorised redirect URI</strong> in your Google Cloud Console:') . '<br><code>' . $callback_url . '</code></p>',
    ];

    $form['gmail']['days_back'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Days of email to scan'),
      '#default_value' => $config->get('days_back') ?: 1,
      '#min'           => 1,
      '#max'           => 30,
      '#description'   => $this->t('How many days back to look for new emails (e.g. 1 = last 24 hours).'),
    ];

    // ── Anthropic ──────────────────────────────────────────────────────────
    $form['anthropic'] = [
      '#type'  => 'details',
      '#title' => $this->t('Anthropic / Claude API'),
      '#open'  => TRUE,
    ];

    $form['anthropic']['anthropic_api_key'] = [
      '#type'          => 'password',
      '#title'         => $this->t('Anthropic API key'),
      '#default_value' => $config->get('anthropic_api_key'),
      '#description'   => $this->t('Get a key at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>. Leave blank to keep the existing key.'),
      '#attributes'    => ['autocomplete' => 'new-password'],
    ];

    // ── Actions ────────────────────────────────────────────────────────────
    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Save configuration'),
    ];

    $form['actions']['authorize'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('💾 Save & Authorize Gmail'),
      '#submit'                  => ['::submitAndAuthorize'],
      '#button_type'             => 'primary',
    ];

    // Show the Run Import button only when we have a token
    if ($has_token) {
      $form['run'] = [
        '#type'        => 'details',
        '#title'       => $this->t('Run import'),
        '#open'        => TRUE,
      ];
      $form['run']['run_link'] = [
        '#type'   => 'markup',
        '#markup' => '<p>' . $this->t('Gmail is authorized and ready. Click below to import job emails now.') . '</p>'
                   . '<a href="' . Url::fromRoute('gmail_importer.run')->toString() . '" class="button button--primary button--action">▶ Run Gmail Import Now</a>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Nothing special – empty password fields mean "keep existing".
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->saveConfig($form_state);
    $this->messenger()->addStatus($this->t('Configuration saved.'));
  }

  /**
   * Save config then start the OAuth flow.
   */
public function submitAndAuthorize(array &$form, FormStateInterface $form_state) {
  $this->saveConfig($form_state);

  // Build the Google OAuth authorization URL directly here
  $config = $this->config('gmail_importer.settings');
  $client_id = $config->get('gmail_client_id');
  $callback_url = Url::fromRoute('gmail_importer.oauth_callback', [], ['absolute' => TRUE])->toString();

  $params = http_build_query([
    'client_id'     => $client_id,
    'redirect_uri'  => $callback_url,
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/gmail.readonly',
    'access_type'   => 'offline',
    'prompt'        => 'consent',
  ]);

  $google_auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;

  $response = new TrustedRedirectResponse($google_auth_url);
  $form_state->setResponse($response);
}

  /**
   * Persist config, skipping blank password fields.
   */
  private function saveConfig(FormStateInterface $form_state): void {
    $config = $this->config('gmail_importer.settings');

    $config->set('gmail_client_id', $form_state->getValue('gmail_client_id'));
    $config->set('days_back', (int) $form_state->getValue('days_back'));

    if ($secret = $form_state->getValue('gmail_client_secret')) {
      $config->set('gmail_client_secret', $secret);
    }
    if ($key = $form_state->getValue('anthropic_api_key')) {
      $config->set('anthropic_api_key', $key);
    }

    $config->save();
  }

}
