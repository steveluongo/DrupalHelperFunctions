<?php

namespace Drupal\helper\Services;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;


/**
 * Class EntityTools.
 *
 * @file providing helpful Entity functions to share across custom modules
 */

class EntityTools {

  /**
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */

  protected $entityTypeManager;


  /**
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */

  protected $loggerFactory;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, LoggerChannelFactoryInterface $loggerFactory, MessengerInterface $messenger) {
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
  }

  /**
   * ====== HELPERS ======
   */
  public function flattenEntityObjectsArray($entity_array): array {
    $array = [];
    foreach ($entity_array as $entity) {
      $array[$entity->id()] = $entity->label();
    }
    return $array;
  }

  /**
   * ====== NODE ======
   */

  /**
   * Helper function to return all the content types configured on the site.
   * @return array $types an associative array of configured content types
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getContentTypes() {
    return $this->entityTypeManager->getStorage('node_type')->loadMultiple();
  }

  /**
   * ====== FIELDS ======
   */

  /**
   * Helper function to remove prefix from field names.
   *
   * @param string $str
   *   A given string.
   * @param string $prefix
   *   A given string.
   *
   * @return string
   *   The name without the prefix.
   */
  public function removeFieldPrefix(string $str, string $prefix = NULL) {
    $prefix = $prefix ?: $this->getFieldPrefix();
    return strpos($str, $prefix) === 0 ? substr($str, strlen($prefix)) : $str;
  }

  /**
   * Dynamically get the field prefix.
   *
   * @return string|null
   *   The field prefix or nothing
   */
  public function getFieldPrefix() {
    return \Drupal::config('field_ui.settings')->get('field_prefix');
  }

  /**
   * @param string $entity_type_id
   * @param string $field_name
   * @return \Drupal\field\Entity\FieldStorageConfigInterface|null
   */
  public function fieldExists($entity_type_id, $field_name) {
    return FieldStorageConfig::loadByName($entity_type_id, $field_name);
  }

  /**
   * @param $entity_type
   * @param $bundle
   * @param $field_name
   * @param $label
   * @param $required
   * @param $description
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function addTaxonomyFieldToBundle($entity_type, $bundle, $field_name, $label, $required, $description, $vocabulary, $widget_type, $default_value = NULL): void {
    $field_exists = $this->fieldExists($entity_type, $field_name);
    if ($field_exists) {
      $field = FieldConfig::loadByName($entity_type, $bundle, $field_name);
      $message = '';
      if (empty($field)) {
        FieldConfig::create(
        [
          'field_name' => $field_name,
          'entity_type' => $entity_type,
          'bundle' => $bundle,
          'label' => $label,
          'required' => $required,
          'default_value' => [
            'target_id' => $default_value,
          ],
          'translatable' => TRUE,
          'description' => t(
            $description),
          'settings' => [
            'handler_settings' => [
              'target_bundles' => [
                $vocabulary => $vocabulary,
              ],
              'auto_create' => FALSE,
            ],
          ],
        ]
        )->save();

        $message .= "\n" . t(
          "Field @a created in bundle @b.",
          ['@a' => $field_name, '@b' => $bundle]
          );
        $this->messenger->addStatus($message);
        $this->loggerFactory->get('entity_tools')->notice($message);

        // Add field to entity form
        $this->addFieldtoEntityForm($entity_type, $bundle, $field_name, $widget_type);

      }
    }
  }

  /**
   * @param $entity_type
   * @param $bundle
   * @param $field_name
   * @param $widget_type
   * @return void
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function addFieldtoEntityForm($entity_type, $bundle, $field_name, $widget_type): void {
    $message = '';
    // Assign widget settings for the 'default' form mode.
    $displayForm = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load($entity_type . '.' . $bundle . '.default')
      ->setComponent($field_name, [
        'type'   => $widget_type,
        'weight' => 100,
      ]);

    $messagePart = '';
    if ($displayForm->save()) {
      $message .= t(
          "The form display of @a in bundle @b has been updated.",
          ['@a' => $field_name, '@b' => $bundle]
        );
      $this->messenger->addStatus($message);
      $this->loggerFactory->get('entity_tools')->notice($message);

    }
    else {
      $message .= "\n" . t(
          "The form display of @a in bundle @b could not be set.",
          ['@a' => $field_name, '@b' => $bundle]
        );
      $this->messenger->addError($message);
      $this->loggerFactory->get('entity_tools')->error($message);

    }

  }

  /**
   * Helper function to set existing field as required.
   *
   * @param $entity_type
   * @param $bundle
   * @param $field_name
   * @param $widget_type
   *
   * @return void
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function setFieldRequirement($entity_type, $bundle, $field_name, $required): void {
    $field_exists = $this->fieldExists($entity_type, $field_name);
    if ($field_exists) {
      $field = FieldConfig::loadByName($entity_type, $bundle, $field_name);
      if ($field) {
        $field->setRequired($required);
        $field->save();
        $requirement = 'on';
        if(!$required) {
          $requirement = 'off';
        }
        $message = t(
          "The field requirement setting for @a has been turned @b in bundle @c.",
          ['@a' => $field_name, '@b' => $requirement, '@c' => $bundle ]
        );
        $this->messenger->addStatus($message);
        $this->loggerFactory->get('entity_tools')->notice($message);
      }
    }
  }

  /**
   * Helper function to check if a field is required.
   *
   * @param $entity_type
   * @param $bundle
   * @param $field_name
   * @param $required
   *
   * @return bool|null
   */
  public function isFieldRequired($entity_type, $bundle, $field_name) {
    $field_exists = $this->fieldExists($entity_type, $field_name);
    if ($field_exists) {
      $field = FieldConfig::loadByName($entity_type, $bundle, $field_name);
      if ($field) {
        return $field->isRequired();
      }
    }
    return NULL;
  }

  /**
   * Helper function to remove a field from a content type.
   * @param $entity_type
   * @param $bundle
   * @param $field_name
   *
   * @return void
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function removeFieldFromBundle($entity_type, $bundle, $field_name): void {
    $message = '';
    $field = FieldConfig::loadByName($entity_type, $bundle, $field_name);
    if (!empty($field)) {
      $field->delete();
      $message .= "\n" . t(
          "@a in bundle @b has been deleted.",
          ['@a' => $field_name, '@b' => $bundle]
        );
      $this->messenger->addStatus($message);
      $this->loggerFactory->get('entity_tools')->notice($message);
    }
  }

  /**
   * ====== TAXONOMY ======
   */

  /**
   * Helper function to create a new taxonomy term.
   * @param string $vocabulary_id
   *   the machine name of the vocabulary
   * @param string $term_name
   *   the name of the taxonomy term
   *
   * @return \Drupal\taxonomy\Entity\Term|FALSE $new_term returns a taxonomy term entity
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createTerm($vocabulary_id, $term_name) {
    $term = $this->getTerm($vocabulary_id, $term_name);
    if (!$term) {
      $new_term = Term::create([
        'vid' => $vocabulary_id,
        'name' => $term_name,
      ]);

      $new_term->enforceIsNew();
      $new_term->save();

      $message = t(
          "@a has been added to @b.",
          ['@a' => $term_name, '@b' => $vocabulary_id]
        );
      $this->messenger->addStatus($message);
      $this->loggerFactory->get('entity_tools')->notice($message);
      return $new_term;

    }

    return FALSE;
  }

  /**
   * Helper function to create a new taxonomy term.
   * @param string $vocabulary_id
   *   the machine name of the vocabulary
   * @param string $term_name
   *   the name of the taxonomy term
   *
   * @return array
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createOrGetTerm($vocabulary_id, $term_name) {
    $term = (array)$this->getTerm($vocabulary_id, $term_name);
    $term = reset($term);
    if (!empty($term)) {
      return $term->id();
    }else{
      $new_term = Term::create([
        'vid' => $vocabulary_id,
        'name' => $term_name,
      ]);

      $new_term->enforceIsNew();
      $new_term->save();

      $message = t(
        "@a has been added to @b.",
        ['@a' => $term_name, '@b' => $vocabulary_id]
      );
      $this->messenger->addStatus($message);
      $this->loggerFactory->get('entity_tools')->notice($message);
      return $new_term->id();
    }
  }

  /**
   * Helper function to delete a taxonomy term.
   * @param string $vocabulary_id
   *   the machine name of the vocabulary
   * @param string $term_name
   *   the name of the taxonomy term
   *
   * @return bool shows whether the delete function was successful.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function deleteTerm($vocabulary_id, $term_name) {
    $term = $this->getTerm($vocabulary_id, $term_name);
    if ($term) {
      reset($term)->delete();
      // todo write action to Drupal Log
      $message = t(
        "@a has been removed from @b.",
        ['@a' => $term_name, '@b' => $vocabulary_id]
      );
      $this->messenger->addStatus($message);
      $this->loggerFactory->get('entity_tools')->notice($message);
      return TRUE;
    }
    $message = t(
      "Unable to remove @a from @b.",
      ['@a' => $term_name, '@b' => $vocabulary_id]
    );
    $this->messenger->addStatus($message);
    $this->loggerFactory->get('entity_tools')->notice($message);
    return FALSE;
  }

  /**
   * Helper function to get a taxonomy term.
   * @param string $vocabulary_id
   *   the machine name of the vocabulary
   * @param string $term_name
   *   the name of the taxonomy term
   *
   * @return term|FALSE loaded taxonomy term entity or FALSE
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getTerm($vocabulary_id, $term_name) {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $term = $storage->loadByProperties([
      'vid' => $vocabulary_id,
      'name' => $term_name,
    ]);

    if ($term) {
      return $term;
    }

    return FALSE;
  }

  /**
   * Helper function to get a taxonomy term.
   * @param string $vocabulary_id
   *   the machine name of the vocabulary
   * @param string $term_name
   *   the name of the taxonomy term
   *
   * @return term|FALSE loaded taxonomy term entity or FALSE
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getTermID($vocabulary_id, $term_name) {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $term = $storage->loadByProperties([
      'vid' => $vocabulary_id,
      'name' => $term_name,
    ]);

    if ($term) {
      return reset($term)->id();
      //return $term;
    }

    return FALSE;
  }

  public function getTermNameByID($vocabulary_id, $term_id){
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $term = $storage->loadByProperties([
      'vid' => $vocabulary_id,
      'tid' => $term_id,
    ]);
    //only one term will ever be returned, hence the reset
    return ($term)? reset($term)->getName(): null;
  }
  /**
   * Load the term entities for a given vocabulary.
   * @param $vocabulary_id
   *
   * @return \Drupal\Core\Entity\EntityInterface[]|null
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getTermsByVocabulary($vocabulary_id) {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties([
      'vid' => $vocabulary_id,
    ]);

    if ($terms) {
      return $terms;
    }

    return NULL;
  }

  /**
   * Useful if you want to format a vocabulary as a select list in form API.
   * @param $vocabulary_id
   *
   * @return array|null
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getVocbaularyTermsAsArray($vocabulary_id) {
    $loaded_terms = $this->getTermsByVocabulary($vocabulary_id);
    if ($loaded_terms) {
      $terms = [];
      foreach ($loaded_terms as $loaded_term) {
        $terms[$loaded_term->id()] = $loaded_term->getName();
      }
      return $terms;
    }

    return NULL;
  }

  /**
   * ====== ROLES ======
   */

  /**
   * Returns all role entities configured for a site.
   */
  public function getRoles(): array {
    return \Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple();
  }

  /**
   * Helper function to get a role entity by id.
   * @param $user_role
   *   The Drupal role id
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getRoleById($user_role) {
    $storage = \Drupal::entityTypeManager()->getStorage('user_role');
    $role = $storage->load($user_role);
    if ($role) {
      return $role;
    }
    return NULL;
  }

  /**
   * Retrieves the IDs of nodes that match the filtering criteria
   *
   * @param array $conditions
   *   A multidimensional array of conditions $conditions[operator] = array of values
   *
   * @return array
   *   An array of matching node ids
   *
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getNodeIDsByFields($conditions) {
      // Load entities by their property values.
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      //this will get the ids of the course
      $query = $storage->getQuery();
      $query->accessCheck(TRUE);
      //add each condition from the passed array before we execute the query
      foreach ($conditions as $operator => $values){
        foreach ( $values as $field => $value){
          $query->condition($field,$value,$operator);
        }
      }
      //return any matching node ids
      return $query->execute();
  }

  /**
   * Creates content of the specified typeID, tested for nodes and paragraphs
   *
   * @param array $fields
   *   the field values that are assigned to the content
   *
   * @param string $typeID
   *   the typeID of the Entity that we're looking up and returning
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   the entity which we are creating
   *
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function createContent($fields,$typeID='node'){
    $content = \Drupal::entityTypeManager()->getStorage($typeID)->create($fields);
    $content->enforceIsNew(TRUE);
    try { //save the content and catch exceptions
      $content->save();
    }
    catch (\Exception $e) {
      $message = $e->getMessage().' Failed to create '.$typeID.' with '.print_r($fields,true);
      $this->loggerFactory->get('entity_tools')->error($message);
      $this->messenger->addError($message);
      return null;
    }

    return $content;
  }

  /**
   * Updates Nodes by the ids passed to it with the fields passed to it
   *
   * @param array|string $nodeID
   *   the id(s) of the nodes to update, can be an array of ids or a single id
   *
   * @param array $fields
   *   the fields that we will assign to the node(s)
   *
   * @return integer
   *  the number of nodes that were updated
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function updateNodesByIDs($nodeID,$fields):int{
    //if the node id is empty then nothing to do here
    if (empty($nodeID)) return 0;
    //load entities by their property values
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nodeID);
    //get paragraph storage to be used in the loop for deletions
    $paragraphs = \Drupal::entityTypeManager()->getStorage('paragraph');
    //initiate update counter
    $updates = 0;
    foreach ( $nodes as $node ){
      //get all of the field definitions
      $definitions = $node->getFieldDefinitions();
      //look through the fields and take our action depending on the field type
      foreach ( $fields as $field => $value ){
        $type = $definitions[$field]->getType();
        switch( $type ){
          case "entity_reference_revisions":
            // For fields that consist of paragraphs we'll delete the old paragraphs before updating
            // get all of the paragraph ids from the field, then delete them
            $paragraphIDs = array_column($node->get($field)->getValue(), 'target_id');
            try{ $paragraphs->delete($paragraphs->loadMultiple($paragraphIDs)); }
            catch (\Exception $e) {
                $message = $e->getMessage().' Failed to delete paragraphs: '.print_r($paragraphIDs,true);
                $this->loggerFactory->get('entity_tools')->error($message);
                $this->messenger->addError($message);
            }
          case "string_long":
          case "string":
          default:
            // Update the data with the new value if it's not empty
            if (!empty($value)) $node->set($field, $value);
            break;
        }
      }
      try { //save the node and catch exceptions
        $node->save();
      }
      catch (\Exception $e) {
        $message = $e->getMessage().' Failed to update node. '.print_r($fields,true);
        $this->loggerFactory->get('entity_tools')->error($message);
        $this->messenger->addError($message);
        continue;
      }
      //if we're here then add one to the update counter
      $updates +=1;
    }
    return $updates;
  }

  /**
   * Retrieves the corresponding nodes for the provided ids
   *
   * @param array $nodeIDs
   *   the id(s) of the nodes to retrieve
   *
   *
   * @return array
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getNodesForIDs($nodeIDs):array{
    return \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nodeIDs);
  }

  /**
   * Updates Nodes by the ids passed to it with the fields passed to it
   *
   * @param string $paragraphID
   *   the id of the paragraph to delete
   *
   *
   * @return void
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function deleteParagraphsInField($paragraphID){
    $entity = \Drupal::entityTypeManager()->getStorage('paragraph')->load($paragraphID);
    $entity->delete();
  }
}
