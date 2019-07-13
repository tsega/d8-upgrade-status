<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Extension\Extension;

interface DeprecationAnalyserInterface {

  /**
   * Analyse the codebase of an extension including all its sub-components.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension to analyse.
   *
   * @return null
   *   Errors are logged to the logger, data is stored to keyvalue storage.
   */
  public function analyse(Extension $extension);

}
