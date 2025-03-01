<?php

namespace Drupal\h5pimporter\Plugin\H5pContentType;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager for H5P content type plugins.
 */
class H5pContentTypeManager extends DefaultPluginManager {

  /**
   * Constructs a new H5pContentTypeManager.
   *
   * @param \Traversable $namespaces
   *   An object that implements Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to cache plugin definitions.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/H5pContentType', $namespaces, $module_handler, 'Drupal\h5pimporter\Plugin\H5pContentType\H5pContentTypeInterface', 'Drupal\h5pimporter\Annotation\H5pContentType');
    $this->alterInfo('h5p_content_type_info');
    $this->setCacheBackend($cache_backend, 'h5p_content_type_plugins');
  }
}
