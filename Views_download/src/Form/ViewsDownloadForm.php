<?php

/**
 * @file
 * Contains \Drupal\views_file_download\Form\ViewsDownloadForm
 */

namespace Drupal\views_file_download\Form;

use Drupal;
use ZipArchive;
use Drupal\Core\Form\FormBase;
use Drupal\views\ViewExecutable;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Drupal\views_file_download\Controller\ViewsDownloadController;

/**
 * Views Download Form
 */
class ViewsDownloadForm extends FormBase {


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'views_download_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['vdf_action'] = [
      '#type' =>  'submit',
      '#value' => $this->t('Download Files'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var Drupal\views_file_download\Controller\ViewsDownloadController $vdc */
    /** @var Drupal\view\ViewExecutable $view */

   $view =  views_views_pre_render($view->id() == 'view_name');
    $operations = [ $this->DownloadFiles()
    ];

    $batch = [
      'title' => $this->t('Preparing File Download...'),
      'operations' => $operations,
      'finished' => 'DownloadFilesCallback',
    ];
    batch_set($batch);
  }


  public function DownloadFiles() {

    /** @var Drupal\Core\File\FileSystemInterface $fileSystem */
    // Prepare destination directory.
    $directory = 'public://folder_name';
    if (!file_exists($directory)) {
      $fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    }

    // Open zip archive.
    $zip = new ZipArchive();
    $zip_filepath = $directory . '/file_name' . '.zip';
    $result_test = $zip->open($zip_filepath, (ZipArchive::CREATE | ZipArchive::OVERWRITE));


    function views_file_download_views_pre_render(ViewExecutable $view, $directory, $file_system, $zip) {
      /** @var \Drupal\file\Entity\File $files **/
      /** @var \Drupal\Core\Url $url **/
      /** @var \Drupal\media\Entity\Media $media **/
      /** @var Drupal\Core\File\FileSystem $file_system */
      /** @var Drupal\core\File $file_dir */

      $batch_results = [];
      $message = 'Preparing Zip file and adding files...';

      if ($view->id() == 'view_name') {
        $results = $view->result;
        foreach ($results as $result) {

          $message = 'Preparing Zip file and adding files...';

          //Grab the media ID of the views result
          $mid = $result->media_field_data_node__field_cde_file_mid;

          $batch_results = $mid;
          //Load the media entity and then load the File entity to get the correct URI
          $media->load($mid);
          $mid_tid = $media->field_media_file_1->target_id;
          $fid = $files->load($mid_tid);
          $file_uri = $fid->getFileUri();


          $new_file_path = $file_system->realpath($file_uri);

          if (file_exists($new_file_path)) {
            //add the files to the zip archive(container file path, filename)
            $result_test = $zip->addFile($new_file_path, $fid->filename->value);
          } else {
            \Drupal::logger('views_file_download')->warning($fid->filename->value . 'File could not be added to zip archive.');
          }
        }
      }
      //close zip file when complete
      $result_test = $zip->close();
      $context['message'] = $message;
      $context['batch_results'] = $batch_results;
      $zip_uri = $url->fromUri($directory . '/' . 'file_name' . '.zip');
      if (!$result_test) {
        \Drupal::logger('views_file_download')->warning('Zip archive could not be closed.');
      }
    }

    function DownloadFilesCallback($success, $batch_results, $operations) {
      // The 'success' parameter means no fatal PHP errors were detected. All
      // other error management should be handled using 'results'.
      if ($success) {
        $message = \Drupal::translation()->formatPlural(
          count($batch_results),
          'One files processed.',
          '@count files processed.'
        );
      } else {
        $message = t('Finished with an error.');
      }
      Drupal::messenger()->addMessage($message);
    }

    $zip_uri = $zip_filepath;
    // Let other modules provide headers and controls access to the file.
    $headers = $this
      ->moduleHandler()
      ->invokeAll('file_download', [
        $zip_uri,
      ]);
    return new BinaryFileResponse($zip_filepath, 200, $headers, 'private');
  }

}
