<?php
/*
 * Description: Helpers for this plugin and other modules
 */

namespace Seravo;

if ( ! class_exists('Helpers') ) {

  class Helpers {

    // Check if this if the site is running in Vagrant
    public static function is_development() {
      return (getenv('WP_ENV') && getenv('WP_ENV') === 'development');
    }

    // Check if this is a live production site
    public static function is_production() {
      return (getenv('WP_ENV') && getenv('WP_ENV') === 'production');
    }

    // Check if this is staging shadow
    // There shouldn't be difference between this and production,
    // but might be useful in the future.
    public static function is_staging() {
      return (getenv('WP_ENV') && getenv('WP_ENV') === 'staging');
    }

  }

}
