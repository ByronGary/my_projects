<?php

namespace Drupal\Pdf_print;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityInterface;

/**
 * Interface BookUtilityInterface
 *
 * @package Drupal\Pdf_print
 */
interface BookUtilityInterface
{

    /**
     * Determine if the passed entity is in a book outline.
     *
     * @param EntityInterface $entity
     *   The entity to check.
     *
     * @return bool
     *   TRUE if the entity is in a book. FALSE if it's not.
     */
    public function isEntityInBook(EntityInterface $entity);

    /**
     * Check the book settings and return all bundles allowed in a book.
     *
     * @return array
     */
    public function allowedBookBundles();

    /**
     * Get the title of the book based on the book id.
     *
     * @param int $book_id
     *   The book id as an integer.
     *
     * @return string | NULL
     *   Returns the title of the book or NULL if a book couldn't be found.
     */
    public function getBookTitle($book_id);

    /**
     * Determine if the node id passed is in a book.
     *
     * @param int $nid
     *   The node ID as an integer.
     *
     * @return bool
     *   TRUE if the node id is in a book. FALSE otherwise.
     */
    public function isNodeIdInBook($nid);

    /**
     * Given a book id return a flat tree structure.
     *
     * @param int $book_id
     *   The book id.
     *
     * @return array
     *   Returns an array of node ids.
     */
    public function getFlatBookTree($book_id);

    /**
     * Recursively loop through the book structure looking for node ids.
     *
     * @param array $tree
     *   The book tree structure.
     * @param array $array
     *   The array collecting node ids.
     */
    public function parseTreeForNids(array $tree, array &$array);

    /**
     * Get a flat array of a node and all it's children.
     *
     * @param int $nid
     *   The node id of a book.
     * @param array $array
     *   The array collecting node ids.
     * @param boolean $all_children
     *
     */
    public function getChildren($nid, array &$array, $all_children = false);

    /**
     * Update the book and all it's children. Can be publish or unpublished.
     *
     * @param int $book_id
     *   The book id of the book we are updating.
     * @param bool $publishBook
     *   (optional) True if publishing the book, False if unpublishing.
     * @return bool
     *   True if the book was successfully updated. False if something happened.
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function updateBook($book_id, $publishBook = FALSE);

    /**
     * Delete the book and all it's children.
     *
     * @param int $book_id
     *   The book id of the book we are deleting.
     *
     * @return bool
     *   True if the book was successfully deleted. False if something happened.
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function deleteBook($book_id);

    /**
     * Filter the string to be more url friendly.
     *
     * @param string $text
     *   Original string
     *
     * @return string|string[]
     *   Lowercase and spaces turned to dashes.
     */
    public function url_filter($text);

    /**
     * Create the child page url based on input.
     *
     * @param string $title
     *   The title of the chapter page.
     * @param string $sub_title
     *   The subtitle of the chapter page.
     *
     * @return string
     *   The new string.
     */
    public function child_page_filter($title, $sub_title);

    /**
     * Create the volume page url based on input.
     *
     * @param string $sub_title
     *   The subtitle of the volume page.
     *
     * @return string
     *   The new string.
     */
    public function volume_page_filter($sub_title);

}
