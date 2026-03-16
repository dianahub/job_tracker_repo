<?php

/**
 * Implements hook_theme().
 */
function gmail_importer_theme() {
  return [
    'gmail_importer_result' => [
      'variables' => [
        'saved'   => 0,
        'skipped' => 0,
        'log'     => [],
      ],
      'template' => 'gmail-importer-result',
    ],
  ];
}
