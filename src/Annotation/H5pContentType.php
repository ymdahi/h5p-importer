<?php

namespace Drupal\h5pimporter\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines an H5P content type annotation object.
 *
 * @Annotation
 */
class H5pContentType extends Plugin {

  /**
   * The ID of the H5P content type.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable label for the H5P content type.
   *
   * @var string
   */
  public $label;
}
