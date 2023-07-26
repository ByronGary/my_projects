<?php

namespace Drupal\views_file_download\Plugin\Action;

use Drupal;
use ZipArchive;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Action description.
 *
 * @Action(
 *   id = "views_download_action",
 *   label = @Translation("Views File Download"),
 *   type = "",
 *   confirm = FALSE
 * )
 */
class ViewsDownloadAction extends ViewsBulkOperationsActionBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL) {
    $entity;
    if (!isset($this->context['sandbox']['counter'])) {
      $this->context['sandbox']['counter'] = 0;
    }

    // Do some processing..
    $this->context['sandbox']['counter']++;

    if ($this->context['sandbox']['counter'] == 30) {
      $this->message($this->t('We have just hit 30.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entites) {

    $zip = new ZipArchive();

    // Prepare destination directory.
    $file_system = \Drupal::service('file_system');
    $directory = 'public://' . 'folder_name';
    if (!file_exists($directory)) {
      $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }

    $zip_filename = '/file_name-' . uniqid('', TRUE) . '.zip';
    // Assign correct zip file path
    $zip_filepath = $file_system->realPath($directory . $zip_filename);
    $zip_uri = $directory . $zip_filename;

    $res = $zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($res == TRUE) {
      // Do some processing..
      foreach ($entites as $result) {

        $file_ids = $result->field_cde_file->getValue();
        foreach ($file_ids as $fds => $file_id) {
          $mids = array_values($file_id);
          $mid = Media::load($mids[0]);
          $mid_t = $mid->field_media_file_1[0]->target_id;
          $fid = File::load($mid_t);

          $file_name = $fid->getFilename();
          $file_uri = $fid->getFileUri();
          $new_file_path = $file_system->realpath(strval($file_uri));


          if (file_exists($new_file_path)) {
            //add the files to the zip archive(container file path, filename)
            $zip->addFile($new_file_path, $file_name);
          } else {
            \Drupal::logger('views_file_download')->warning($fid->filename->value . 'File could not be added to zip archive.');
          }
        }
      }
      //close zip file when complete
      $zip->close();
    }
    if (!$zip) {
      \Drupal::logger('views_file_download')->warning('Zip archive could not be closed.');
    } else {
      \Drupal::logger('views_file_download')->warning('Zip archive completed.');
    }

    $this->context['sandbox'];

    $file_generator = Drupal::service('file_url_generator');
    if (file_exists($zip_filepath)) {
      // ZIP file will be managed by Drupal.
      $file = File::create([
        'filename' => \basename($zip_filepath),
        'filemime' => 'application/zip',
        'filesize' => $zip_filepath,
        'uri' => $zip_uri,
        'uid' => \Drupal::currentUser()->id(),
        'status' => 0,
      ]);
      $file->save();

      $relative_url = $file_generator->generateAbsoluteString($file->getFileUri());
      $this->messenger()->addStatus($this->t('Export file created, <a href=":url" target="_blank">Click here</a> to download.', [':url' => $relative_url]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\views\ViewEntityInterface $object */
    $result = $object->access('view', $account, TRUE);
    return $return_as_object ? $result : $result->isAllowed();
  }
}
