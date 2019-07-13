<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\Core\Extension\ThemeHandler;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use http\Exception\InvalidArgumentException;

/**
 * Collects projects collated for the purposes of upgrade status.
 */
class ProjectCollector implements ProjectCollectorInterface {

  use StringTranslationTrait;

  /**
   * The list of available modules.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandler
   */
  protected $themeHandler;

  /**
   * The list of available profiles.
   *
   * @var \Drupal\Core\Extension\ProfileExtensionList
   */
  protected $profileExtensionList;

  /**
   * A list of allowed extension types.
   *
   * @var array
   */
  protected $allowedTypes = [
    'module',
    'theme',
    'profile',
  ];

  /**
   * Constructs a \Drupal\upgrade_status\ProjectCollector.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list service.
   * @param \Drupal\Core\Extension\ThemeHandler $theme_handler
   *   The theme extension handler service.
   */
  public function __construct(
    ModuleExtensionList $module_extension_list,
    ThemeHandler $theme_handler,
    ProfileExtensionList $profile_extension_list
  ) {
    $this->moduleExtensionList = $module_extension_list;
    $this->themeHandler = $theme_handler;
    $this->profileExtensionList = $profile_extension_list;
  }

  /**
   * {@inheritdoc}
   */
  public function collectProjects() {
    $projects = ['custom' => [], 'contrib' => []];
    $modules = $this->moduleExtensionList->reset()->getList();
    $themes = $this->themeHandler->rebuildThemeData();
    $profiles = $this->profileExtensionList->getList();
    $extensions = array_merge($modules, $themes, $profiles);
    unset($modules, $themes, $profiles);

    /** @var \Drupal\Core\Extension\Extension $extension */
    foreach ($extensions as $key => $extension) {

      if ($extension->origin === 'core') {
        // Ignore core extensions for the sake of upgrade status.
        continue;
      }

      if ($extension->getType() !== 'profile' && $extension->status === 0) {
        // Ignore disabled extensions.
        continue;
      }

      // If the project is already specified in this extension, use that.
      $project = isset($extension->info['project']) ? $extension->info['project'] : '';
      if (array_key_exists($project, $projects['custom'])
        || array_key_exists($project, $projects['contrib'])
      ) {
        // If we already have a representative of this project in the list,
        // don't add this extension.
        // @todo Make sure to use the extension with the shortest file path.
        continue;
      }

      // For extensions that are not in core and no project was specified,
      // they are assumed to be custom code. Drupal.org packages contrib
      // extensions with a project key and composer packages also include it.
      if (empty($project)) {
        $projects['custom'][$key] = $extension;
        continue;
      }

      if ($project === 'drupal') {
        // Ensure to omit all core related extension from the list.
        continue;
      }

      // @todo should this use $project as the key?
      $projects['contrib'][$key] = $extension;
    }

    // Collate custom extensions to projects, removing sub-extensions.
    $projects['custom'] = $this->collateCustomExtensionsIntoProjects($projects['custom']);

    return $projects;
  }

  /**
   * Finds topmost custom extension for each extension and keeps only that.
   *
   * @param \Drupal\Core\Extension\Extension[] $projects
   *   List of all enabled custom extensions.
   *
   * @return \Drupal\Core\Extension\Extension[]
   *   List of custom extensions, with only the topmost custom extension left
   *   for each extension that has a parent extension.
   */
  protected function collateCustomExtensionsIntoProjects(array $projects) {
    foreach ($projects as $name_a => $data_a) {
      $subpath_a = $data_a->subpath;
      $subpath_a_length = strlen($subpath_a);

      foreach ($projects as $name_b => $data_b) {
        $subpath_b = $data_b->subpath;
        // If the extension is not the same but the beginning of paths match,
        // remove this extension from the list as it is part of another one.
        if ($name_b != $name_a && substr($subpath_b, 0, $subpath_a_length) === $subpath_a) {
          unset($projects[$name_b]);
        }
      }
    }
    return $projects;
  }

  /**
   * {@inheritdoc}
   */
  public function loadProject(string $type, string $project_machine_name) {
    if (!in_array($type, $this->allowedTypes)) {
      throw new InvalidArgumentException($this->t('Type must be either module or theme.'));
    }

    if ($type === 'module') {
      return $this->moduleExtensionList->get($project_machine_name);
    }

    if ($type === 'profile') {
      return $this->profileExtensionList->get($project_machine_name);
    }

    return $this->themeHandler->getTheme($project_machine_name);
  }

}
