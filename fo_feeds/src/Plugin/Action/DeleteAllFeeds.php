<?php

namespace Drupal\fo_feeds\Plugin\Action;


use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\feeds\Plugin\Action\FeedActionBase;

/**
 * Redirects to a feed clear form.
 *
 * @Action(
 *   id = "feeds_feed_delete_all",
 *   label = @Translation("Delete imported items and feed"),
 *   type = "feeds_feed",
 *   confirm_form_route_name = "cchi_fo.multiple_delete_confirm",
 * )
 */
class DeleteAllFeeds extends FeedActionBase {

  const ACTION = 'feeds_feed_delete_all';

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    if('delete'){
      $object->access('delete', $account, $return_as_object);}
    elseif('clear'){
      $object->access('clear', $account, $return_as_object);
    }
    return $object;
  }
}
