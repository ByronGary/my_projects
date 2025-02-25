<?php

/**
 * @file
 * Post update functions for Feeds.
 */

use Drupal\Core\Config\Entity\ConfigEntityUpdater;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\feeds\Feeds\Parser\CsvParser;
use Drupal\feeds\FeedTypeInterface;
use Drupal\field\FieldStorageConfigInterface;

/**
 * Adds action plugin 'feeds_feed_delete_all'.
 */
function fo_feeds_post_update_add_feeds_feed_delete_all() {
  if (!\Drupal::entityTypeManager()->getStorage('action')->load('feeds_feed_delete_all')) {
    \Drupal::entityTypeManager()->getStorage('action')
      ->create([
        'id' => 'feeds_feed_delete_all',
        'label' => 'Delete imported items and Feed of selected feeds',
        'type' => 'feeds_feed',
        'plugin' => 'feeds_feed_delete_all',
      ])
      ->save();
  }
}
