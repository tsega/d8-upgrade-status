<?php

namespace Drupal\upgrade_status\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\upgrade_status\ProjectCollectorInterface;
use Drupal\upgrade_status\ScanResultFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class ScanResultController extends ControllerBase {

  /**
   * The scan result formatter service.
   *
   * @var \Drupal\upgrade_status\ScanResultFormatter
   */
  protected $resultFormatter;

  /**
   * The project collector service.
   *
   * @var \Drupal\upgrade_status\ProjectCollectorInterface
   */
  protected $projectCollector;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a \Drupal\upgrade_status\Controller\ScanResultController.
   *
   * @param \Drupal\upgrade_status\ScanResultFormatter $result_formatter
   *   The scan result formatter service.
   * @param \Drupal\upgrade_status\ProjectCollectorInterface $project_collector
   *   The project collector service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    ScanResultFormatter $result_formatter,
    ProjectCollectorInterface $project_collector,
    RendererInterface $renderer
  ) {
    $this->resultFormatter = $result_formatter;
    $this->projectCollector = $project_collector;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('upgrade_status.result_formatter'),
      $container->get('upgrade_status.project_collector'),
      $container->get('renderer')
    );
  }

  /**
   * Builds content for the error list page/popup.
   *
   * @param string $type
   *   Type of the extension, it can be either 'module' or 'theme.
   * @param string $project_machine_name
   *   The machine name of the project.
   *
   * @return array
   *   Build array.
   */
  public function resultPage(string $type, string $project_machine_name) {
    $extension = $this->projectCollector->loadProject($type, $project_machine_name);
    return $this->resultFormatter->formatResult($extension);
  }

  /**
   * Generates single project export.
   *
   * @param string $type
   *   Type of the extension, it can be either 'module' or 'theme.
   * @param string $project_machine_name
   *   The machine name of the project.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response object.
   */
  public function resultExport(string $type, string $project_machine_name) {
    $extension = $this->projectCollector->loadProject($type, $project_machine_name);
    $result = $this->resultFormatter->getRawResult($extension);

    $build = ['#theme' => 'upgrade_status_html_export'];
    $build['#projects'][empty($extension->info['project']) ? 'custom' : 'contrib'] = [
      $project_machine_name =>  $this->resultFormatter->formatResult($extension),
    ];

    $fileDate = $this->resultFormatter->formatDateTime($result['date'], 'html_datetime');
    $filename = 'single-export-' . $project_machine_name . '-' . $fileDate . '.html';
    $response = new Response($this->renderer->renderRoot($build));
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}
