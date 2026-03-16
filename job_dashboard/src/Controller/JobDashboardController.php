<?php
namespace Drupal\job_dashboard\Controller;
use Drupal\Core\Controller\ControllerBase;
class JobDashboardController extends ControllerBase {
  public function page() {
    return [
      '#theme' => 'job_dashboard',
      '#attached' => ['library' => ['job_dashboard/app']],
    ];
  }
}