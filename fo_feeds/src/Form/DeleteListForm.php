<?php

namespace Drupal\fo_feeds\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\feeds\Form\ActionMultipleForm;


/**
 * Provides a confirmation form for deleting imported items.
 */
class DeleteListForm extends ActionMultipleForm {
  /**
   * The entity being used by this form.
   *
   * @var \Drupal\Core\Entity\ContentEntity
   */
  protected $entity;


  /**
   * Returns the action ID.
   *
   * @return string
   *   The action ID.
   */
  public function getActionId(): string {
    // Implement the logic to return a specific action ID.
    return 'feeds_feed_delete_all';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(count($this->feedIds), 'Are you sure you want to delete all imported items of the selected feed?', 'Are you sure you want to delete all imported items of the selected feed?');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }


  /**
   * {@inheritdoc}
   */
  public function storage($storage) {
    $storage = $this->storage;
    return $storage;
  }
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {


    //** We are deleting the Feed items before the submit */
    if ($form_state->getValue('confirm') && !empty($this->feedIds)) {
      $count = 0;
      $inaccessible_feeds = [];
      $feeds_to_delete = [];

      $feeds = $this->storage->loadMultiple($this->feedIds);
      foreach ($feeds as $feed) {

        if ($feed->access('clear')) {
          $count++;
          $feed->startBatchClear();
        } else {
          $inaccessible_feeds[] = $feed;
        }


        $this->tempStoreFactory->get($this->getActionId())->delete($this->currentUser->id() . ':feeds_feed');
        $this->logger('feeds')->notice('Deleted imported items of @count feeds.', ['@count' => $count]);
        $this->messenger()->addMessage($this->formatPlural($count, 'Deleted items of 1 feed.', 'Deleted items of @count feeds.'));

        //**There is a submit handler deleting the Feed Type */
        $form_state->setRedirect('feeds.admin');
      }
    }
  }
}
