<?php

namespace Drupal\fsa_auto_print;

use Drupal\Core\Entity\EntityInterface;
use Drupal\file\Entity\File;


/**
 * Interface PdfBuilderInterface
 *
 * @package Drupal\Pdf_print
 */
interface PdfBuilderInterface
{

    /**
     * Create a batch job for the node that was published. Also create the whole
     * book.
     *
     * @param EntityInterface $entity
     *   A node entity.
     * @param integer $step
     *   The current operation step being performed.
     * @return void
     */
    public function main(EntityInterface $entity, $step);

    /**
     * @param EntityInterface $entity
     *  Gather a list of entities
     *
     */
    public function createPdfFile(EntityInterface $entity, &$context);

    /**
     * @param EntityInterface $entity
     *  The entity is used to create the filename by grabbing the Title
     *
     * @return mixed
     *  Returns the created file
     */
    public function createFileEntity(EntityInterface $entity, &$context);

    /**
     * @param EntityInterface $entity
     *  We check for the Entity
     *
     * @return mixed
     *  Returns the file entity and saves it into the media storage bundle
     */
    public function createMediaEntity(EntityInterface $entity, &$context);

    /**
     * @param $engine
     * Checks for the print engine to be used for the PDF creation
     *
     * @return mixed
     *  Returns the print engine to be used
     *
     */
    public function getPrintEngine($engine);

    /**
     * @param EntityInterface $entity
     *  Checks for the entity to be used
     *
     * @return mixed
     *  Returns the Book title from grabbing the book_id
     *
     */
    public function getBookTitle(EntityInterface $entity);
}
