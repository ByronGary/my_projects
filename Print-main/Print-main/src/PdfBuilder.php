<?php

namespace Drupal\Pdf_print;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\entity_print\Plugin\EntityPrintPluginManagerInterface;
use Drupal\entity_print\PrintBuilderInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\NodeInterface;
use Jurosh\PDFMerge\PDFMerger;

/**
 * Class PdfBuilder
 *
 * Creates PDFs based on a book entity and it's parent.
 */
class PdfBuilder implements PdfBuilderInterface
{

    use StringTranslationTrait;
    use DependencySerializationTrait;

    /**
     * The entity print plugin manager (print engine) service.
     *
     * @var EntityPrintPluginManagerInterface
     */
    protected $entityPrintPluginManager;

    /**
     * The print builder service.
     *
     * @var PrintBuilderInterface
     */
    protected $printBuilder;

    /**
     * The file system service.
     *
     * @var FileSystemInterface
     */
    protected $fileSystem;

    /**
     * The entity type manager service.
     *
     * @var EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * The entity type manager service.
     *
     * @var BookUtilityInterface
     */
    protected $bookUtility;


    /**
     * The account proxy manager service.
     *
     * @var  AccountProxyInterface
     */
    protected $currentUser;

    /**
     * The messenger service.
     *
     * @var MessengerInterface
     */
    protected $messenger;

    /**
     * The logger factory service.
     *
     * @var LoggerChannelFactoryInterface
     */
    protected $loggerFactory;

    /**
     * The shared temp store service.
     *
     * @var SharedTempStoreFactory
     */
    protected $sharedTempStore;

    /**
     * PdfBuilder constructor.
     *
     * @param EntityPrintPluginManagerInterface $entity_print_plugin_manager
     * The Entity Print Plugin Manager service is used to define the Print Engine
     *   used to build the PDF
     * @param PrintBuilderInterface $print_builder
     * The Print Builder service is used to acquire the filename and entity to be
     *   saved as a pdf in the required directory
     * @param FileSystemInterface $file_system
     * The File service is used to create the file name of th entity
     * @param EntityTypeManagerInterface $entity_type_manager
     * The Entity Type Manager is used to load entities
     * @param BookUtilityInterface $book_utility
     * The Book utility service is used to load book_ids
     * @param AccountProxyInterface $current_user
     * The Account Proxy service is used to grab the current user
     */
    public function __construct(EntityPrintPluginManagerInterface $entity_print_plugin_manager, PrintBuilderInterface $print_builder, FileSystemInterface $file_system, EntityTypeManagerInterface $entity_type_manager, BookUtilityInterface $book_utility, AccountProxyInterface $current_user, MessengerInterface $messenger, LoggerChannelFactoryInterface $loggerFactory, SharedTempStoreFactory $shared_temp_store)
    {
        $this->entityPrintPluginManager = $entity_print_plugin_manager;
        $this->printBuilder = $print_builder;
        $this->fileSystem = $file_system;
        $this->entityTypeManager = $entity_type_manager;
        $this->bookUtility = $book_utility;
        $this->currentUser = $current_user;
        $this->messenger = $messenger;
        $this->loggerFactory = $loggerFactory;
        $this->sharedTempStore = $shared_temp_store;
    }

    /**
     * {@inheritdoc}
     */
    public function main(EntityInterface $entity, $step)
    {
        $this->loggerFactory->get('Pdf_print')->notice("Starting the batch job for node: @nid at step: @step", ["@nid" => $entity->id(), "@step" => $step]);
        $batch_builder = (new BatchBuilder());
        $nids = [];
        $nids[] = $entity->book['p2'];
        $nids[] = $entity->book['bid'];

        switch ($step) {
            case 1:
                $batch_builder->setTitle('Creating PDF file')
                    ->setInitMessage('creating...')
                    ->setFinishCallback([$this, 'batchPDFFinished']);
                foreach ($nids as $nid) {
                    if ($nid) {
                        if ($entity->id() != $nid) {
                            $entity = $this->entityTypeManager->getStorage('node')->load($nid);
                        }

                        $batch_builder->addOperation([$this, 'createPdfFile'], [$entity]);
                    }
                }
                break;
            case 2:
                $batch_builder->setTitle('Creating file entity')
                    ->setInitMessage('creating...')
                    ->setFinishCallback([$this, 'batchPDFFinished']);
                foreach ($nids as $nid) {
                    if ($nid) {
                        if ($entity->id() != $nid) {
                            $entity = $this->entityTypeManager->getStorage('node')->load($nid);
                        }

                        $batch_builder->addOperation([$this, 'createFileEntity'], [$entity]);
                    }
                }
                break;
            case 3:
                $batch_builder->setTitle('Creating media entity')
                    ->setInitMessage('creating...')
                    ->setFinishCallback([$this, 'batchPDFFinished']);
                foreach ($nids as $nid) {
                    if ($nid) {
                        if ($entity->id() != $nid) {
                            $entity = $this->entityTypeManager->getStorage('node')->load($nid);
                        }

                        $batch_builder->addOperation([$this, 'createMediaEntity'], [$entity]);
                    }
                }
                break;
        }

        // Add the batch redirect url object.
        $batch_array = $batch_builder->toArray();
        // $batch_array['batch_redirect'] = $entity->toUrl();
        batch_set($batch_array);
    }

    public function newBatch($node_id)
    {
        /** @var NodeInterface $node */
        $node = $this->entityTypeManager->getStorage('node')->load($node_id);
        $batch_builder = new BatchBuilder();
        $batch_builder->setTitle($this->t("Printing entities"))
            ->setInitMessage($this->t("Printing file(s) for @title", ['@title' => $node->getTitle()]))
            ->setFinishCallback([$this, 'batchPDFFinished']);

        // print the current node
        $batch_builder->addOperation([$this, 'finalPrint'], [$node->id()]);
        $batch_builder->addOperation([$this, 'createFileEntity'], [$node->id()]);
        $batch_builder->addOperation([$this, 'createMediaEntity'], [$node->id()]);

        // print the Branch node (Volume node)
        if ($node->id() != $node->book['p2'] && $node->id() != $node->book['bid']) {
            $batch_builder->addOperation([$this, 'finalPrint'], [$node->book['p2']]);
            $batch_builder->addOperation([$this, 'createFileEntity'], [$node->book['p2']]);
            $batch_builder->addOperation([$this, 'createMediaEntity'], [$node->book['p2']]);
        }

        // print the book node
        if ($node->id() != $node->book['bid']) {
            $batch_builder->addOperation([$this, 'finalPrint'], [$node->book['bid']]);
            $batch_builder->addOperation([$this, 'createFileEntity'], [$node->book['bid']]);
            $batch_builder->addOperation([$this, 'createMediaEntity'], [$node->book['bid']]);
        }

        batch_set($batch_builder->toArray());
    }

    public function finalPrint($node_id, &$context)
    {
        // This is the first pass.
        if (empty($context['sandbox'])) {
            $context['sandbox']['progress'] = 0;
            $node = $this->entityTypeManager->getStorage('node')->load($node_id);
            /** @var NodeInterface $node */
            $context['message'] = $this->t("Settings things up for <em>@title</em>", ['@title' => $node->getTitle()]);
            $child_node_ids = [];
            $this->bookUtility->getChildren($node->id(), $child_node_ids);
            $context['sandbox']['max'] = count($child_node_ids);
            // Store the current node and it's children.
            $context['sandbox']['nodes'] = $this->entityTypeManager->getStorage('node')->loadMultiple($child_node_ids);
            $context['sandbox']['page_title'] = $node->getTitle();
            $context['sandbox']['book_name'] = $this->getBookTitle($node);
            $directory = "public://" . $context['sandbox']['book_name'];
            // Make sure the directory exists. Backout if it can't be created.
            $is_directory_ready = $this->fileSystem->prepareDirectory($directory, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
            if (!$is_directory_ready) {
                // The directory we are writing to couldn't be made/updated.
                $this->loggerFactory->get('Pdf_print')->error("Error creating: @dir", ['@dir' => $directory]);
                exit;
            }
            $context['sandbox']['file_uris'] = [];
        }

        // Save 3 nodes into a slice and shift the sandbox nodes.
        $slice = array_splice($context['sandbox']['nodes'], 0, 3);
        $context['message'] = $this->t("Stitching files into <em>@title</em>", ['@title' => $context['sandbox']['page_title']]);
        // A new print engine is required everytime we call savePrintable.
        $print_engine = $this->entityPrintPluginManager->createSelectedInstance('pdf');
        // Save upto 3 nodes in the temporary file scheme.
        $context['sandbox']['file_uris'][] = $this->printBuilder->savePrintable($slice, $print_engine, 'temporary');
        // Update progress
        $context['sandbox']['progress'] += count($slice);

        // This is the last cycle. Merge and save.
        if ($context['sandbox']['progress'] == $context['sandbox']['max']) {
            // Stitch all the files together into the new book.
            $pdf_merger = new PDFMerger;
            foreach ($context['sandbox']['file_uris'] as $file_uri) {
                $pdf_merger->addPDF($file_uri);
            }
            $context['message'] = $this->t("Saving <em>@title</em>", ['@title' => $context['sandbox']['page_title']]);
            $context['results']['new_file_uri'] = 'public://' . $context['sandbox']['book_name'] . '/' . $context['sandbox']['page_title'] . '.pdf';

            // Merge all the files we have so far into a new file.
            $pdf_merger->merge('file', $context['results']['new_file_uri']);
        }

        $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }

    /**
     * {@inheritdoc}
     */
    public function getBookTitle(EntityInterface $entity)
    {
        $this->loggerFactory->get('Pdf_print')->notice("Get book title.");
        // Here we grab the book ID from the node to get the book title
        $book_id = $entity->book['bid'];
        $book_entity = $this->entityTypeManager->getStorage('node')->load($book_id);

        return $book_entity->getTitle();
    }

    /**
     * Builds PDF's for nodes and their children.
     *
     * @param EntityInterface $entity
     *   A node entity.
     * @param array $context
     *   An array containing info about the batch operation.
     */
    public function buildABook(EntityInterface $entity, &$context)
    {
        $this->loggerFactory->get('Pdf_print')->notice("Build a book method called.");
        $context['message'] = $this->t("Creating a pdf for @page", ["@page" => $entity->label()]);
        $file_uri = $this->createPdfFile($entity);
        // See if this PDF file has a File entity.
        $results = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $file_uri]);
        // If not create a File entity and a Media entity.
        if (empty($results)) {
            $this->loggerFactory->get('Pdf_print')->notice("File created for first time.");
            $file_entity = $this->createFileEntity($file_uri, $entity);
            $media_entity = $this->createMediaEntity($file_entity, $entity);
        } else {
            $this->loggerFactory->get('Pdf_print')->notice("File already exists.");
            // This file already exists. Update all matching file entities.
            foreach ($results as $file_entity) {
                $this->updateMediaEntity($file_entity, $entity);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createPdfFile(EntityInterface $entity, &$context)
    {
        $context['message'] = $this->t("Creating pdf file");
        $this->loggerFactory->get('Pdf_print')->notice("Create PDF method started");
        // Get the PHP engine used to build the PDF.
        $pdf_engine = $this->getPrintEngine('phpwkhtmltopdf');

        // Create the directory based on the book title.
        $directory = $this->getBookTitle($entity);
        $dir = "public://$directory";
        $this->fileSystem->prepareDirectory($dir, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

        // The filename SHOULDN'T include the uri schema.
        // It should include the directory without a leading slash
        $filename = "$directory/" . $entity->getTitle() . ".pdf";

        //Lets grab a list of book nids to be saved
        $nids = [];
        $this->bookUtility->getChildren($entity->id(), $nids);
        $entities = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

        $this->loggerFactory->get('Pdf_print')->notice("Calling save printable method.");
        // Create the file and return the file uri location
        $file_uri = $this->printBuilder->savePrintable($entities, $pdf_engine, 'public', $filename);
        $this->loggerFactory->get('Pdf_print')->notice("Save printable method success");

        // Save the file uri of the entity that was just built to the store.
        $store = $this->sharedTempStore->get('Pdf_print');
        $store->set('file_uri:' . $entity->id(), $file_uri);
    }

    /**
     * {@inheritdoc}
     */
    public function getPrintEngine($engine)
    {
        $this->loggerFactory->get('Pdf_print')->notice("Getting the pdf engine.");
        // we return the Print Engine
        return $this->entityPrintPluginManager->createInstance($engine);
    }

    /**
     * {@inheritdoc}
     */
    public function createFileEntity($node_id, &$context)
    {
        /** @var NodeInterface $node */
        $node = $this->entityTypeManager->getStorage('node')->load($node_id);
        $context['message'] = $this->t("Creating file entity for <em>@title</em>", ['@title' => $node->getTitle()]);
        $file_uri = $context['results']['new_file_uri'];

        $results = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $file_uri]);
        if (empty($results)) {
            $this->loggerFactory->get('Pdf_print')->notice("File created for first time.");
            // here we are creating the file and filename for the entity
            $file = $this->entityTypeManager->getStorage('file')->create(array(
                'uid' => $this->currentUser->getAccount()->id(),
                'filename' => $node->getTitle() . '.pdf',
                'uri' => $file_uri,
                'status' => 1,
            ));
            $file->save();
            $context['results']['file_id'] = $file->id();
        } else {
            $this->loggerFactory->get('Pdf_print')->notice("File already exists.");
            $file = array_pop($results);
            $context['results']['file_id'] = $file->id();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createMediaEntity($node_id, &$context)
    {
        /** @var NodeInterface $node */
        $node = $this->entityTypeManager->getStorage('node')->load($node_id);
        $context['message'] = $this->t("Creating media entity for <em>@title</em>", ['@title' => $node->getTitle()]);
        $this->loggerFactory->get('Pdf_print')->notice("Calling create media entity method.");
        $file_id = $context['results']['file_id'];
        $file_uri = $context['results']['new_file_uri'];

        $results = $this->entityTypeManager->getStorage('media')->loadByProperties([
            'bundle' => 'media_bundle_name',
            'field_media_file' => [
                'target_id' => $file_id,
            ],
        ]);

        // No media entities using that file id were found.
        if (empty($results)) {
            // Create a new media entity and assign it that file id.
            $media_entity = $this->entityTypeManager->getStorage('media')->create(array(
                'bundle' => 'media_bundle_name',
                'uid' => $this->currentUser->getAccount()->id(),
                'title' => $node->label(),
                'field_media_file' => [
                    'target_id' => $file_id,
                ],
                'field_pages' => $this->getPageCount($file_uri),
            ));
            $media_entity->save();
        } else {
            // At least one media is using that file, update their page counts.
            foreach ($results as $media_entity) {
                $media_entity->set('field_pages', $this->getPageCount($file_uri));
                $media_entity->save();
            }
        }
    }

    public function getPageCount($file_uri)
    {
        $this->loggerFactory->get('Pdf_print')->notice("Calculating page numbers.");
        $pdftext = file_get_contents($file_uri);
        return preg_match_all("/\/Page\W/", $pdftext, $dummy);
    }

    public function updateMediaEntity(File $file_entity, EntityInterface $book_node)
    {
        $this->loggerFactory->get('Pdf_print')->notice("Updating media entity.");
        $results = $this->entityTypeManager->getStorage('media')->loadByProperties([
            'bundle' => 'media_bundle_name',
            'field_media_file' => [
                'target_id' => $file_entity->id(),
            ],
        ]);

        // Update the page count for media entities using the file uri.
        /** @var Media $media_entity */
        foreach ($results as $media_entity) {
            $media_entity->set('field_pages', $this->getPageCount($file_entity->getFileUri()));
            $media_entity->save();
        }
    }

    /**
     * Batch operation completed callback.
     *
     * @param bool $success
     *   True if the batch finished without errors.
     * @param array $results
     *   An array with batch results.
     * @param array $operations
     *   The operations array.
     */
    public function batchPDFFinished($success, $results, $operations, $elapsed)
    {
        if ($success) {
            $this->messenger->addMessage($this->t('Batch job finished'));
        } else {
            $this->messenger->addError($this->t('Error: Something went wrong!'));
        }
        $this->messenger->addStatus("Time elapsed: " . $elapsed);
    }

}
