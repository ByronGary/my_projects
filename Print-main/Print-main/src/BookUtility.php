<?php

namespace Drupal\Pdf_print;

use Drupal\book\BookManagerInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\group\Entity\GroupContent;
use Drupal\node\Entity\Node;
use Entity;

/**
 * Class BookUtility
 *
 * @package Drupal\Pdf_print
 */
class BookUtility implements BookUtilityInterface
{

    use StringTranslationTrait;
    use DependencySerializationTrait;

    /**
     * The entity type manager interface
     *
     * @var EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * The config factory service.
     *
     * @var ConfigFactoryInterface
     */
    protected $configFactory;

    /**
     * The book manager service.
     *
     * @var BookManagerInterface
     */
    protected $bookManager;

    /**
     * The database connection service.
     *
     * @var Connection
     */
    protected $connection;

    /**
     * The entity field manager service.
     *
     * @var EntityFieldManagerInterface
     */
    protected $entityFieldManager;

    /**
     * The logger service
     *
     * @var LoggerChannelFactoryInterface
     */
    protected $loggerFactory;

    /**
     * The shared temp store service
     *
     * @var SharedTempStoreFactory
     */
    protected $sharedTempStore;

    /**
     * The messenger service.
     *
     * @var MessengerInterface
     */
    protected $messenger;

    /**
     * BookUtility constructor.
     *
     * @param EntityTypeManagerInterface $entity_type_manager
     *   The entity type manager service used to load entities.
     * @param ConfigFactoryInterface $config_factory
     *   The config factory service used to load book configuration settings.
     * @param BookManagerInterface $book_manager
     *   The book manager service used to load book tree menus.
     * @param Connection $connection
     *   The database connection service used to query the db for book children.
     * @param MessengerInterface
     *   The messenger service use to display messages to the user.
     */
    public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, BookManagerInterface $book_manager, Connection $connection, EntityFieldManagerInterface $entity_field_manager, LoggerChannelFactoryInterface $logger_channel_factory, SharedTempStoreFactory $shared_temp_store, MessengerInterface $messenger)
    {
        $this->entityTypeManager = $entity_type_manager;
        $this->configFactory = $config_factory;
        $this->bookManager = $book_manager;
        $this->connection = $connection;
        $this->entityFieldManager = $entity_field_manager;
        $this->loggerFactory = $logger_channel_factory;
        $this->sharedTempStore = $shared_temp_store;
        $this->messenger = $messenger;
    }

    /**
     * {@inheritdoc}
     */
    public function allowedBookBundles()
    {
        $config = $this->configFactory->get('book.settings');

        return is_null($config->get('allowed_types')) ? [] : $config->get('allowed_types');
    }

    /**
     * {@inheritdoc}
     */
    public function getBookTitle($book_id)
    {
        if (!is_int($book_id)) {
            // The book id must be an integer.
            return NULL;
        }

        $book_entity = $this->entityTypeManager->getStorage('node')->load($book_id);

        // The book doesn't exist.
        if (is_null($book_entity)) {
            return NULL;
        } else {
            return $book_entity->label();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getFlatBookTree($book_id)
    {
        $book = $this->bookManager->bookTreeAllData($book_id);
        $array = [];
        $this->parseTreeForNids(reset($book), $array);

        return $array;
    }

    /**
     * {@inheritdoc}
     */
    public function parseTreeForNids(array $tree, array &$array)
    {
        if (empty($tree)) {
            return;
        }
        $array[] = $tree['link']['nid'];
        if (!empty($tree['below'])) {
            foreach ($tree['below'] as $branch) {
                $this->parseTreeForNids($branch, $array);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function partialClone($target_branch_id, $target_book_id)
    {
        $node_ids = [];
        $this->getChildren($target_branch_id, $node_ids, TRUE);
        $batch_builder = (new BatchBuilder());
        $batch_builder->setInitMessage($this->t("Cloning branch node..."))
            ->setTitle($this->t("Partial clone in progress"))
            ->addOperation([$this, "determineGroup"])
            ->setFinishCallback([$this, 'finishClone']);

        foreach ($node_ids as $node_id) {
            $batch_builder->addOperation([$this, 'partialNodeClone'], [$node_id]);
            $batch_builder->addOperation([$this, 'nodeSave']);
            $batch_builder->addOperation([$this, 'addToGroup']);
            $batch_builder->addOperation([$this, 'addToBook'], [$node_id, $target_book_id]);
        }

        batch_set($batch_builder->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function getChildren($nid, array &$array, $all_children = false)
    {
        if ($node = $this->entityTypeManager->getStorage('node')->load($nid)) {
            if ($this->isEntityInBook($node)) {
                // Get all children including unpublished content.
                if (!$all_children) {
                    if (!$node->isPublished()) {
                        return;
                    }
                }

                if ($node->book['has_children'] == "0") {
                    $array[] = $node->id();
                    return;
                } else {
                    $array[] = $node->id();
                    $results = $this->getBranchIds($nid);

                    foreach ($results as $child_nid) {
                        $this->getChildren($child_nid, $array, $all_children);
                    }
                }
            } else {
                return;
            }
        } else {
            return;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isEntityInBook(EntityInterface $entity)
    {
        // Only nodes can be in a book outline.
        if ($entity->getEntityTypeId() != 'node') {
            return FALSE;
        }
        if (isset($entity->book)) {
            if ($entity->book['bid'] != 0) {
                $book_entity = $this->entityTypeManager->getStorage('node')
                    ->load($entity->book['bid']);
                if (is_null($book_entity)) {
                    // The book couldn't be loaded.
                    return FALSE;
                } else {
                    return TRUE;
                }
            } else {
                // A book id of 0 means it's not a part of a book.
                return FALSE;
            }
        } else {
            return FALSE;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getBranchIds($node_id)
    {
        $query = $this->connection->select('book', 'book');
        $results = $query->condition('book.pid', $node_id, '=')
            ->fields('book', ['nid'])
            ->orderBy('weight', 'ASC')
            ->execute()->fetchCol();

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function isNodeIdInBook($nid)
    {
        // Check if a numeric string was passed.
        if (is_string($nid)) {
            if (!ctype_digit($nid)) {
                return FALSE;
            }
        } else {
            if (!is_int($nid)) {
                // if a real number was passed make sure it's an integer.
                return FALSE;
            }
        }
        $node = $this->entityTypeManager->getStorage('node')->load($nid);

        if (is_null($node)) {
            // The node couldn't be loaded.
            return FALSE;
        } else {
            return $this->isEntityInBook($node);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function batchClone($book_id)
    {
        $batch_builder = (new BatchBuilder());
        $batch_builder->setTitle('Cloning handbook')
            ->setInitMessage('Cloning handbook...')
            ->setFinishCallback([$this, 'finishClone']);

        $entity_ids = [];
        // Get all Entities in the book.
        $this->getChildren($book_id, $entity_ids, TRUE);

        if (empty($entity_ids)) {
            return;
        }

        // This operation determined the group to clone nodes into.
        $batch_builder->addOperation([$this, 'determineGroup'], []);
        // Clone all nodes in the book, each node gets it's own operation.
        foreach ($entity_ids as $entity_id) {
            $batch_builder->addOperation([$this, 'cloneBookNode'], [$entity_id]);
        }
        batch_set($batch_builder->toArray());
    }

    /**
     * Batch Callback: Get the group entity to use across operations.
     *
     * @param array $context
     *   Batch operation context array.
     */
    public function determineGroup(&$context)
    {
        $context['message'] = $this->t("Determine the group id.");
        $context['results']['success_count'] = 0;
        $context['results']['fail_count'] = 0;

        // Get all groups installed
        $groups = $this->entityTypeManager->getStorage('group')->loadMultiple();
        $handbook_group_entity = NULL;

        // loop through all groups and get the handbook group.
        foreach ($groups as $group) {
            if ($group->bundle() == 'fsa_handbook') {
                $handbook_group_entity = $group;
            }
        }

        // Make this entity available to all subsequent operations.
        $context['results']['handbook_group_entity'] = $handbook_group_entity;
        $context['results']['id_tracker'] = [0 => 0];
    }

    /**
     * {@inheritdoc}
     */
    public function updateBook($book_id, $publishBook = FALSE)
    {
        $batch_builder = (new BatchBuilder());
        $batch_builder->setInitMessage("Updating nodes...")
            ->setTitle("Updating Handbook")
            ->setFinishCallback([$this, 'updateFinished']);

        $entity_ids = [];

        // Get all children, including unpublished nodes.
        $this->getChildren($book_id, $entity_ids, TRUE);

        if (empty($entity_ids)) {
            return;
        }

        $batch_builder->addOperation([$this, 'lockNodes'], [$entity_ids]);

        foreach ($entity_ids as $entity_id) {
            $batch_builder->addOperation([$this, 'updateHandbookNode'], [$publishBook, $entity_id]);
        }

        batch_set($batch_builder->toArray());
    }

    /**
     * Batch Callback: Lock the nodes being updated so there are no interruptions.
     *
     * @param array $entity_ids
     *   The node ids of all entities being updated.
     * @param array $context
     *   Batch data.
     * @return void
     */
    public function lockNodes($entity_ids, &$context)
    {
        // Lock the books so fsa_workflow doesn't interrupt.
        $store = $this->sharedTempStore->get('node_lock');
        $store->set('node_lock', $entity_ids);
    }

    /**
     * Batch Callback: Update the node id being passed. Will either publish or
     * unpublish based on the boolean passed.
     *
     * @param boolean $publishBook
     *   TRUE if the node should be published. FALSE to unpublish the node.
     * @param int $entity_id
     *   The node id to update.
     * @param array $context
     *   Batch operations context.
     * @return void
     */
    public function updateHandbookNode($publishBook, $entity_id, &$context)
    {
        /** @var Node $node */
        $node = $this->entityTypeManager->getStorage('node')->load($entity_id);
        $node->set('moderation_state', $publishBook ? 'published' : 'unpublished');
        $node->set('status', $publishBook ? 1 : 0);
        $node->set('published_at', $publishBook ? '' : $node->published_at->value);

        try {
            $node->save();
            $context['message'] = $this->t('Updating node: <em>@title</em>.', ['@title' => $node->label()]);
            $context['results']['success_count']++;
        } catch (EntityStorageException $e) {
            $context['results']['fail_count']++;
            $this->loggerFactory->get('fsa_handbook_utilities')->error('ERROR: Cannot update node id @nid, message: @message', ['@nid' => $node->id(), '@message' => $e->getMessage()]);
        }
    }

    /**
     * Batch Callback: Update nodes batch job has finished.
     *
     * @param boolean $success
     *   TRUE if the batch job finished without errors.
     * @param array $results
     *   An array with information about the batch process.
     * @param array $operations
     *   If the batch job failed contains information about the processes that failed.
     * @return void
     */
    public function updateFinished($success, $results, $operations)
    {
        if ($success) {
            $this->messenger->addMessage($this->t("@total nodes were successfully updated.", ['@total' => $results['success_count']]));
        } else {
            $this->messenger->addError($this->t("@total nodes couldn't be updated.", ['@total' => $results['fail_count']]));
        }

        // Delete the key, unlock books.
        $store = $this->sharedTempStore->get('node_lock');
        $store->delete('node_lock');
    }

    /**
     * {@inheritdoc}
     */
    public function deleteBook($book_id)
    {
        $entity_ids = [];

        // Get all children, including unpublished nodes.
        $this->getChildren($book_id, $entity_ids, TRUE);

        if (empty($entity_ids)) {
            return;
        }

        // Create a batch operation to delete nodes.
        $batch_builder = (new BatchBuilder());
        $batch_builder->setInitMessage("Deleting the book")
            ->setTitle("Deleting book")
            ->setFinishCallback([$this, 'bookDeleteFinish']);

        foreach ($entity_ids as $entity_id) {
            $batch_builder->addOperation([$this, "bookDeleteNode"], [$entity_id]);
        }

        batch_set($batch_builder->toArray());
    }

    /**
     * Batch Callback: Delete a single node from the book.
     *
     * @param int $entity_id
     *   The node id of the node to be deleted.
     * @param array $context
     *   The batch context array.
     */
    public function bookDeleteNode($entity_id, &$context)
    {
        $node = $this->entityTypeManager->getStorage('node')->load($entity_id);
        $context['message'] = $this->t('Deleting node: <em>@title</em>.', ['@title' => $node->label()]);
        try {
            $this->entityTypeManager->getStorage('node')->delete([$node]);
            $context['results']['success_count']++;
        } catch (EntityStorageException $e) {
            $context['results']['fail_count']++;
            $this->loggerFactory->get('fsa_handbook_utilities')->error('ERROR: Cannot delete node @id, message: @message', ['@id' => $node->id(), '@message' => $e->getMessage()]);
        }
    }

    /**
     * Batch Callback: Called when the batch job is completed.
     *
     * @param boolean $success
     *   TRUE if the batch operation completed without issues.
     * @param array $results
     *   Contains information about the batch job.
     * @param array $operations
     *   Contains information about any batch operation that failed.
     */
    public function bookDeleteFinish($success, $results, $operations)
    {
        if ($success) {
            $this->messenger->addMessage($this->t("The book was successfully deleted. @success_count nodes deleted.", ['@success_count' => $results['success_count']]));
        } else {
            $this->messenger->addWarning($this->t("The book did not delete correctly. @fail_count nodes couldn't be deleted.", ['@fail_count' => $results['fail_count']]));
        }
    }

    /**
     * Begin a batch operation to delete parts of a book.
     *
     * @param int $target_branch_id
     *   The node id of the branch to delete from.
     */
    public function partialDelete($target_branch_id)
    {

        // Get the ids that need to be deleted. Start deleting from the tail end.
        $target_node_ids = [];
        $target_node_ids = array_merge([$target_branch_id], $this->getBranchIds($target_branch_id));
        $target_node_ids = array_reverse($target_node_ids);

        $batch_builder = (new BatchBuilder());
        $batch_builder->setTitle($this->t("Delete branch"))
            ->setInitMessage($this->t("Deleting nodes..."))
            ->setFinishCallback([$this, 'bookDeleteFinish']);

        foreach ($target_node_ids as $target_node_id) {
            $batch_builder->addOperation([$this, 'bookDeleteNode'], [$target_node_id]);
        }

        batch_set($batch_builder->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function child_page_filter($title, $sub_title)
    {
        $title = $this->url_filter($title);
        $sub_title = $this->url_filter($sub_title);

        if (preg_match("/chapter/mi", $sub_title)) {
            $number = explode('-', $sub_title);
            $text = substr($sub_title, 0, 2) . $number[count($number) - 1];
            $text .= '-' . $title;
        } elseif (preg_match('/appendix/mi', $sub_title)) {
            $letter = explode('-', $sub_title);
            $text = substr($sub_title, 0, 3) . 'x-' . $letter[count($letter) - 1];
            $text .= '-' . $title;
        } else {
            $text = $sub_title . '-' . $title;
        }

        return $text;
    }

    /**
     * {@inheritdoc}
     */
    public function url_filter($text)
    {
        $text = strtolower($text);
        return str_replace(' ', '-', $text);
    }

    /**
     * {@inheritdoc}
     */
    function volume_page_filter($sub_title)
    {
        $sub_title = $this->url_filter($sub_title);
        $split = explode('-', $sub_title);

        return 'vol' . $split[count($split) - 1];
    }

}
