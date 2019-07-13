<?php

namespace Drupal\upgrade_status;

/**
 * Provides an interface for project collection.
 */
interface ProjectCollectorInterface {

  /**
   * Collect projects of installed modules grouped by custom and contrib.
   *
   * @return array
   *   An array keyed by 'custom' and 'contrib' where each array is a list
   *   of projects grouped into that project group. Custom modules get a
   *   project name based on their topmost parent custom module and only
   *   that topmost custom module gets included in the list. Each item is
   *   a \Drupal\Core\Extension\Extension object in both arrays.
   */
  public function collectProjects();

  /**
   * Returns a single extension based on type and machine name.
   *
   * @param string $type
   *   One of 'module' or 'theme' to signify the type of the extension.
   * @param string $project_machine_name
   *   Machine name for the extension.
   *
   * @return \Drupal\Core\Extension\Extension
   *   A project if exists.
   *
   * @throws \InvalidArgumentException
   *   If the type was not one of the allowed ones.
   * @throws \Drupal\Core\Extension\Exception\UnknownExtensionException
   *   If there was no extension with the given name.
   */
  public function loadProject(string $type, string $project_machine_name);

}
