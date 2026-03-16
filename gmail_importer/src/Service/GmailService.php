<?php

namespace Drupal\gmail_importer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;

/**
 * Handles Gmail OAuth and message retrieval.
 */
class GmailService {

  protected ConfigFactoryInterface $configFactory;
  protected FileSystemInterface $fileSystem;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected MessengerInterface $messenger;

  /** Directory (private://) where token is stored. */
  const TOKEN_DIR = 'private://gmail_importer';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger
  ) {
    $this->configFactory = $config_factory;
    $this->fileSystem    = $file_system;
    $this->loggerFactory = $logger_factory;
    $this->messenger     = $messenger;
  }

  // ── Token helpers ──────────────────────────────────────────────────────

  private function tokenPath(): string {
    return self::TOKEN_DIR . '/token.json';
  }

  public function hasToken(): bool {
    return file_exists($this->fileSystem->realpath($this->tokenPath()));
  }

  public function getToken(): ?array {
    $path = $this->fileSystem->realpath($this->tokenPath());
    if (!$path || !file_exists($path)) {
      return NULL;
    }
    $data = json_decode(file_get_contents($path), TRUE);
    return is_array($data) ? $data : NULL;
  }

  public function saveToken(array $token): void {
    $directory = self::TOKEN_DIR;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);  $path = $this->fileSystem->realpath(self::TOKEN_DIR) . '/token.json';
    file_put_contents($path, json_encode($token));
  }

  public function deleteToken(): void {
    $path = $this->fileSystem->realpath($this->tokenPath());
    if ($path && file_exists($path)) {
      unlink($path);
    }
  }

  // ── OAuth ──────────────────────────────────────────────────────────────

  private function config() {
    return $this->configFactory->get('gmail_importer.settings');
  }

  public function getAuthUrl(): string {
    $params = http_build_query([
      'client_id'     => $this->config()->get('gmail_client_id'),
      'redirect_uri'  => $this->redirectUri(),
      'response_type' => 'code',
      'scope'         => 'https://www.googleapis.com/auth/gmail.readonly',
      'access_type'   => 'offline',
      'prompt'        => 'consent',
    ]);
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
  }

  public function redirectUri(): string {
    return Url::fromRoute('gmail_importer.oauth_callback', [], ['absolute' => TRUE])->toString();
  }

  /**
   * Exchange an auth code for tokens and persist them.
   *
   * @return bool TRUE on success.
   */
  public function exchangeCode(string $code): bool {
    $response = \Drupal::httpClient()->post('https://oauth2.googleapis.com/token', [
      'form_params' => [
        'code'          => $code,
        'client_id'     => $this->config()->get('gmail_client_id'),
        'client_secret' => $this->config()->get('gmail_client_secret'),
        'redirect_uri'  => $this->redirectUri(),
        'grant_type'    => 'authorization_code',
      ],
    ]);

    $data = json_decode((string) $response->getBody(), TRUE);
    if (empty($data['access_token'])) {
      return FALSE;
    }

    $data['created'] = time();
    $this->saveToken($data);
    return TRUE;
  }

  /**
   * Return a valid access token, refreshing if necessary.
   */
  public function getAccessToken(): ?string {
    $token = $this->getToken();
    if (!$token) {
      return NULL;
    }

    // Refresh if within 60 s of expiry.
    $expires_at = ($token['created'] ?? 0) + ($token['expires_in'] ?? 3600);
    if (time() > $expires_at - 60) {
      if (empty($token['refresh_token'])) {
        $this->deleteToken();
        return NULL;
      }
      $token = $this->refreshToken($token['refresh_token']);
      if (!$token) {
        return NULL;
      }
    }

    return $token['access_token'];
  }

  private function refreshToken(string $refresh_token): ?array {
    try {
      $response = \Drupal::httpClient()->post('https://oauth2.googleapis.com/token', [
        'form_params' => [
          'client_id'     => $this->config()->get('gmail_client_id'),
          'client_secret' => $this->config()->get('gmail_client_secret'),
          'refresh_token' => $refresh_token,
          'grant_type'    => 'refresh_token',
        ],
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      if (empty($data['access_token'])) {
        return NULL;
      }
      $data['refresh_token'] = $refresh_token; // preserve refresh token
      $data['created']       = time();
      $this->saveToken($data);
      return $data;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('gmail_importer')->error('Token refresh failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  // ── Gmail API ──────────────────────────────────────────────────────────

  /**
   * Fetch message IDs from Gmail inbox for the configured number of days.
   *
   * @return array List of ['id' => string] arrays.
   */
  public function fetchMessageIds(): array {
    $access_token = $this->getAccessToken();
    if (!$access_token) {
      return [];
    }

    $days     = $this->config()->get('days_back') ?: 1;
    $messages = [];
    $page_token = NULL;
    $client   = \Drupal::httpClient();

    do {
      $query = [
        'q'          => "label:inbox newer_than:{$days}d",
        'maxResults' => 100,
      ];
      if ($page_token) {
        $query['pageToken'] = $page_token;
      }

      $response  = $client->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', [
        'headers' => ['Authorization' => 'Bearer ' . $access_token],
        'query'   => $query,
      ]);
      $data      = json_decode((string) $response->getBody(), TRUE);
      $messages  = array_merge($messages, $data['messages'] ?? []);
      $page_token = $data['nextPageToken'] ?? NULL;
    } while ($page_token);

    return $messages;
  }

  /**
   * Fetch full message data for a single Gmail message ID.
   */
  public function fetchMessage(string $id): ?array {
    $access_token = $this->getAccessToken();
    if (!$access_token) {
      return NULL;
    }
    $response = \Drupal::httpClient()->get(
      "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$id}",
      [
        'headers' => ['Authorization' => 'Bearer ' . $access_token],
        'query'   => ['format' => 'full'],
      ]
    );
    return json_decode((string) $response->getBody(), TRUE);
  }

  // ── Message parsing ────────────────────────────────────────────────────

  public function extractHeader(array $headers, string $name): string {
    foreach ($headers as $h) {
      if (strtolower($h['name']) === strtolower($name)) {
        return $h['value'];
      }
    }
    return '';
  }

  public function extractBody(array $payload): string {
    if (!empty($payload['parts'])) {
      // Prefer plain text.
      foreach ($payload['parts'] as $part) {
        if ($part['mimeType'] === 'text/plain' && !empty($part['body']['data'])) {
          return base64_decode(strtr($part['body']['data'], '-_', '+/'));
        }
      }
      // Fall back to HTML.
      foreach ($payload['parts'] as $part) {
        if ($part['mimeType'] === 'text/html' && !empty($part['body']['data'])) {
          return html_entity_decode(base64_decode(strtr($part['body']['data'], '-_', '+/')));
        }
      }
    }
    if (!empty($payload['body']['data'])) {
      return base64_decode(strtr($payload['body']['data'], '-_', '+/'));
    }
    return '(No body content found)';
  }

}
