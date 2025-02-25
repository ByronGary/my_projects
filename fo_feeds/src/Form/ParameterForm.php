<?php

namespace Drupal\fo_feeds\Form;

use Drupal\feeds\Entity\Feed;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Executable\ExecutableException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\feeds\FeedInterface;

class ParameterForm extends FormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'cchi_fo.settings';

  public function getFormId() {
    return 'cchi_parameter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#theme'] = 'cchi_parameter_form';
    $config = $this->config('cchi_fo.settings');
    $form['fo_feeds_parameter'] = [
      '#type' => 'textarea',
      '#title' => t('Import Funded Projects'),
      '#description' => t('Enter a single application id 10732086 or a comma separated list of ids 10732086, 10732522, 10732582, 10733090'),
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#submit' => ['::submitForm'],
    ];

    return $form;
  }

  /**
   * Creating and import Feed if the parameter does not exist
   *
   * @param  $param
   * @param  $post_parameters
   * @return void
   */
  public function FeedCreation($param, $post_parameters) {
    $feed = Feed::create([
      'type' => 'reporter_api',
      'title' => $param,
      'source' => "https://api.reporter.nih.gov/v2/projects/search",
      'config' => [
        'fetcher' => [
          'auto_detect_feeds' => FALSE,
          'use_pubsubhubbub' => FALSE,
          'always_download' => FALSE,
          'request_timeout' => 30,
          'headers' => "accept: application/json\r\nContent-Type: application/json",
          'post_parameter' => json_encode($post_parameters),
          'post_variant' => 'BODY',
        ]
      ],
    ]);
    $feed->save();
    $feed->import();
    return $feed;
  }

  /**
   * Import Feed when if it already exist
   *
   * @param $param
   * @param  $post_parameters
   * @return void
   */
  public function FeedExist($param, $post_parameters) {
    $query  = \Drupal::database()->select('feeds_feed', 't')
      ->fields('t', ['title']);
    $result = $query->execute()->fetch();
    foreach ($result as $id) {
      if ($id == $param) {
        !$feeds = Feed::loadMultiple($id);
        foreach ($feeds as $feed) {
          $feed->import();
          return $feed;
        }
      }
    }
  }
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {

      $field = $form_state->getValues();
      $parameters = explode(',', $field["fo_feeds_parameter"]);
      foreach ($parameters as $param) {

        //remove white space
        $fields["fo_feeds_parameter"] =  str_replace(' ', '', $param);

        //create post parameter variable
        $post_parameters = [
          'criteria' => [
            'appl_ids' => [$param],
          ],
          'offset' => 0,
        ];

        $query  = \Drupal::database()->select('fo_feeds', 't')
          ->fields('t', ['fo_feeds_parameter']);
        $result = $query->execute()->fetch();
        if ($result == TRUE) {
          foreach ($result as $value) {
            if ($param == $value) {
              \Drupal::messenger()->addMessage($this->t('This ' . $param . ' already exist. Updating Feed'));
              $this->FeedExist($param, $post_parameters);
            } else {
              \Drupal::messenger()->addMessage($this->t('Feed saved and Imported'));
              $this->FeedCreation($param, $post_parameters);
            }
          }
        } else {
          if (!$param) {
            \Drupal::messenger()->addMessage($this->t('This ' . $param . ' already exist. Updating Feed'));
            $this->FeedExist($param, $post_parameters);
          } else {
            \Drupal::messenger()->addMessage($this->t('Feed saved and Imported'));
            $this->FeedCreation($param, $post_parameters);
          }
        }
      }
    } catch (Exception $ex) {
      \Drupal::logger('dn_students')->error($ex->getMessage());
    }
  }
}
