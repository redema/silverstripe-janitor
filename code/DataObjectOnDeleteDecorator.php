<?php

/**
 * Copyright (c) 2012, Redema AB - http://redema.se/
 * 
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 * 
 * * Redistributions of source code must retain the above copyright notice,
 *   this list of conditions and the following disclaimer.
 * 
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 * 
 * * Neither the name of Redema, nor the names of its contributors may be used
 *   to endorse or promote products derived from this software without specific
 *   prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

/**
 * <h1>Summary</h1>
 * 
 * Decorates the onAfterDelete() event for DataObjects to
 * perform extra cleaning to minimize the junk left in the
 * database after a DataObject has been deleted.
 * 
 * <h2>Additional cleaning performed:</h2>
 * 
 * - All classes which the DataObject in question could have
 * been are collected and their tables are checked for junk
 * entries matching the ID of the handled DataObject. This
 * is probably mainly useful for different SiteTree types.
 * 
 * - has_one relationships likning back to handled object
 * are cleaned if the object linking to the handled object
 * has explicitly requested it through has_one_on_delete.
 * 
 * - many_many/belongs_many_many relationships linking to
 * the handled object will be cleaned.
 * 
 * - If the object is Versioned and, *_versions table
 * cleaning is enabled, all old versions of the DataObject
 * will be deleted too. You must manually enable this
 * feature since it will break some Versioned functionality
 * (and cause irreversible data loss).
 * 
 * <h2>More about has_one relationships</h2>
 * 
 * You must explicitly define what should be done when a
 * has_one relationship links back to the handled object in
 * order to have it handled.
 * 
 * To do this, simply add a public static property named
 * $has_one_on_delete to the class which has the $has_one
 * relation. $has_one_on_delete should be an associative
 * array where the key corresponds to a has_one key (relation
 * name) and the value is either "delete" or "set null".
 * 
 * "delete" means that the object will be deleted when the
 * DataObject it links to is deleted.
 * 
 * "set null" means that the field in question will be set
 * to null (technically zero) when the DataObject it links
 * to is deleted.
 * 
 * An example is how PageComments are handled by default by
 * this module, which also shows that the module can be used
 * for other modules and core models:
 * <code>
 * Object::add_static_var('PageComment', 'has_one_on_delete', array(
 *     'Parent' => 'delete',
 *     'Author' => 'set null'
 * ));
 * </code>
 * 
 * Another, more complete example:
 * <code>
 * class First extends DataObject {
 *     public static $has_many = array('Examples' => 'Example');
 * }
 * 
 * class Second extends DataObject {
 *     public static $has_many = array('Examples' => 'Example');
 * }
 * 
 * class Example extends DataObject {
 *     public static $has_one = array(
 *         'First' => 'First',
 *         'Second' => 'Second'
 *     );
 *     public static $has_one_on_delete = array(
 *         'First' => 'delete',
 *         'Second' => 'set null'
 *     );
 * }
 * </code>
 * 
 * <h2>More about many_many relationships</h2>
 * 
 * By default relevant intermediate many_many tables will
 * be cleaned from references to DataObjects being deleted.
 * 
 * This is however not always desired. It is therefore
 * possible to define intermediate tables which should be
 * ignored or only cleaned when one side of the relation
 * is being deleted.
 * 
 * To have intermediate tables ignored when encountered
 * simply add them to the ManyManyCleaner:
 * <code>
 * DataObjectOnDeleteDecorator_ManyManyCleaner::set_ignored_tables(
 *     array('MyPage_Thingies'));
 * </code>
 * 
 * To have intermediate tables cleaned only from one side
 * of the relation is more complicated.
 * 
 * For example, SiteTree needs special handling since some
 * of the many_many relations needs to break under some
 * circumstances:
 * <code>
 * DataObjectOnDeleteDecorator_ManyManyCleaner::set_one_way_tables(array_merge(
 *     array(
 *         'SiteTree_LinkTracking' => 'LinkTracking',
 *         'SiteTree_ImageTracking' => 'ImageTracking',
 *     ), DataObjectOnDeleteDecorator_ManyManyCleaner::get_one_way_tables()
 * ));
 * </code>
 * 
 * Tables only cleaned from one side of the relation is
 * called one way tables. It is represented by an associative
 * array where the keys are intermediate table names and
 * the values are the names of the relation which should
 * cause the table to be cleaned.
 * 
 * Looking closer at SiteTree_ImageTracking:
 * <code>
 * 'SiteTree_ImageTracking' => 'ImageTracking'
 * </code>
 * This tells us that SiteTree_ImageTracking will only be
 * cleaned when intermediate relationships are discovered
 * through the relation named "ImageTracking". Looking closer
 * at this many_many/belongs_many_many relationship reveals:
 * <code>
 * SiteTree: 'ImageTracking' => 'SiteTree'
 * File: 'BackLinkTracking' => 'SiteTree'
 * </code>
 * This means that SiteTree_ImageTracking only will be
 * cleaned when the SiteTree is deleted, not when the File
 * is.
 * 
 * One way tables will obviously not work if the relation
 * has the same name on both sides of the many_many relation.
 * 
 * <h2>Retroactive cleaning</h2>
 * 
 * {@link DataObjectRetroactiveCleanerTask}
 */
class DataObjectOnDeleteDecorator extends DataObjectDecorator {
	
	/**
	 * Cache over available tables in the database.
	 * @var array
	 */
	private static $db_tables = array();
	
	/**
	 * Get an array containing all tables in the database.
	 * 
	 * @param boolean $lowercase
	 * 
	 * @return array
	 */
	public static function get_db_tables($lowercase = true) {
		if (!isset(self::$db_tables[$lowercase])) {
			// Build the cache over tables available in the database.
			self::$db_tables[$lowercase] = DB::tableList();
			if ($lowercase) {
				foreach (self::$db_tables[$lowercase] as $key => &$value)
					$value = strtolower($value);
			}
		}
		return self::$db_tables[$lowercase];
	}
	
	/**
	 * Controls if *_versions tables should be cleaned if the
	 * DataObject is versioned?
	 * @var boolean
	 */
	private static $clean_versions_table = false;
	
	/**
	 * @param boolean $value
	 * @see self::$clean_versions_table
	 */
	public static function set_clean_versions_table($value) {
		self::$clean_versions_table = (bool)$value;
	}
	
	/**
	 * @return boolean
	 * @see self::$clean_versions_table
	 */
	public static function get_clean_versions_table() {
		return self::$clean_versions_table;
	}
	
	/**
	 * @var boolean
	 */
	private static $disabled = false;
	
	/**
	 * @param boolean $value
	 * @see self::$disabled
	 */
	public static function set_disabled($value) {
		self::$disabled = (bool)$value;
	}
	
	/**
	 * @return boolean
	 * @see self::$disabled
	 */
	public static function get_disabled() {
		return self::$disabled;
	}
	
	/**
	 * Try to determine the possible stages for the given $object
	 * if it is versioned.
	 * 
	 * @param DataObject $object The object which will be
	 * checked.
	 * 
	 * @return array The available stages if the object is
	 * versioned.
	 */
	public static function version_stages(DataObject $object) {
		$stages = array();
		$versioned = $object->getExtensionInstance('Versioned');
		if ($versioned) {
			$stages = $versioned->getStages();
		}
		return $stages;
	}
	
	/**
	 * Versioned objects needs special handling, we can not
	 * handle them reliably as long as they exist on a stage.
	 * 
	 * @param DataObject $object The object which will be
	 * checked.
	 * 
	 * @return boolean True if there are other versions for the
	 * object which prevents relations from being handled.
	 */
	public static function version_exist(DataObject $object) {
		if (Object::has_extension($object->ClassName, 'Versioned')) {
			$ID = (int)$object->ID;
			foreach (self::version_stages($object) as $stage) {
				if (Versioned::get_versionnumber_by_stage($object->ClassName, $stage, $ID))
					return true;
			}
		}
		return false;
	}
	
	/**
	 * Hook into the delete event for the owner. If the owner
	 * is versioned, make sure that it has been deleted from
	 * all stages, before taking action.
	 */
	public function onAfterDelete() {
		if (!$this->owner->ID || self::get_disabled() || self::version_exist($this->owner))
			return;
		$this->onAfterDeleteCleaning();
	}
	
	/**
	 * Perform the actual cleaning, without safety checks. This
	 * can be useful in some cases, but only call it directly
	 * if you really know what you are doing!
	 */
	public function onAfterDeleteCleaning() {
		$classHierarchyProbe = new DataObjectOnDeleteDecorator_ClassHierarchyProbe($this->owner);
		$relatedClasses = $classHierarchyProbe->getRelatedClasses();
		
		$this->cleanRelatedRelations($relatedClasses);
		$this->cleanRelatedTables($relatedClasses);
	}
	
	/**
	 * If necessary, clean has_one relations connected to
	 * $this->owner.
	 * 
	 * @param DataObject $object
	 * 
	 * @see DataObjectOnDeleteDecorator_HasManyCleaner
	 */
	public function cleanRelatedHasManyRelations(DataObject $object) {
		$cleanHasManyRelations = new DataObjectOnDeleteDecorator_HasManyCleaner(
			$this->owner->ID, $object);
		$cleanHasManyRelations->clean();
	}
	
	/**
	 * Clean many_many relations connected to $this->owner.
	 * 
	 * @param DataObject $object
	 * 
	 * @see DataObjectOnDeleteDecorator_ManyManyCleaner
	 */
	public function cleanRelatedManyManyRelations(DataObject $object) {
		$manyManyRelationsCleaner = new DataObjectOnDeleteDecorator_ManyManyCleaner(
			$this->owner->ID, $object);
		$manyManyRelationsCleaner->clean();
	}
	
	/**
	 * Clean has_one/many_many relations for classes related to
	 * any of $relatedClasses.
	 * 
	 * @param array $relatedClasses
	 */
	public function cleanRelatedRelations(array $relatedClasses) {
		foreach ($relatedClasses as $class) {
			$instance = singleton($class);
			$this->cleanRelatedHasManyRelations($instance);
			$this->cleanRelatedManyManyRelations($instance);
		}
	}
	
	/**
	 * Clean up in all tables where the DataObject could have
	 * existed, this is probably mainly useful for cleaing up
	 * after different SiteTree types and its subclasses.
	 * 
	 * @param array $relatedClasses
	 */
	public function cleanRelatedTables(array $relatedClasses) {
		$ID = (int)$this->owner->ID;
		$ancestry = $this->owner->getClassAncestry();
		$stages = self::version_stages($this->owner);
		$dbTables = self::get_db_tables();
		$versioned = Object::has_extension($this->owner->ClassName, 'Versioned');
		$relatedClasses = array_filter($relatedClasses, create_function('$obj',
			'return DataObject::has_own_table($obj);'));
		foreach ($relatedClasses as $class) {
			// SilverStripe will clean tables for any directly related
			// classes ("Stage" + possible stages), so running delete
			// queries again for them would be unnecessary.
			$directlyRelated = in_array($class, $ancestry);
			$dirtyTables = $directlyRelated? array(): array($class => 'ID');
			if ($versioned) {
				if (!$directlyRelated) {
					foreach ($stages as $stage) {
						if ($stage != 'Stage')
							$dirtyTables["{$class}_{$stage}"] = 'ID';
					}
				}
				// $directlyRelated or not, SilverStripe never touches the
				// *_versions tables. We might.
				if (self::$clean_versions_table)
					$dirtyTables["{$class}_versions"] = 'RecordID';
			}
			foreach ($dirtyTables as $dirtyTable => $fieldName) {
				if (in_array(strtolower($dirtyTable), $dbTables))
					DB::query("DELETE FROM \"{$dirtyTable}\" WHERE \"{$fieldName}\" = {$ID}");
			}
		}
	}
	
	public function flushCache() {
		// Must be a no-op since it is called each time a DataObject
		// is deleted. If it cleared our static cache variables
		// here, they would in effect be useless.
	}
	
	/**
	 * Remove scaffolded fields for pseudo has_many/has_one
	 * relations added by Janitor from the given FieldSet.
	 * 
	 * @param FieldSet $fields
	 */
	private function removePseudoRelationsFromFieldSet(FieldSet& $fields) {
		$dataFields = $fields->dataFields();
		if ($dataFields) foreach ($dataFields as $field) {
			if (preg_match('/^__/', $field->Name()))
				$fields->removeByName($field->Name());
		}
	}
	
	public function updateCMSFields(FieldSet& $fields) {
		$this->removePseudoRelationsFromFieldSet($fields);
	}
	
	public function updateFrontEndFields(FieldSet& $fields) {
		$this->removePseudoRelationsFromFieldSet($fields);
	}
	
}

/**
 * Helps to determine and flatten a class hierarchy.
 */
class DataObjectOnDeleteDecorator_ClassHierarchyProbe {
	
	/**
	 * @var DataObject
	 */
	protected $object = null;
	
	/**
	 * Collect all subclasses for the given $baseClass recursively.
	 * 
	 * @param string $baseClass
	 * 
	 * @return array
	 */
	protected function collectSubclassesFor($baseClass) {
		$subclasses = ClassInfo::subclassesFor($baseClass);
		$baseClass = array_shift($subclasses);
		return $subclasses;
	}
	
	/**
	 * @param DataObject $object The DataObject which will be
	 * probed.
	 */
	public function __construct(DataObject $object) {
		$this->object = $object;
	}
	
	/**
	 * Get the entire hiearchy for the held DataObject, but
	 * flattened to a one-dimensional array.
	 * 
	 * @return array
	 */
	public function getRelatedClasses()
	{
		$relatedClasses = array();
		$directAncestry = $this->object->getClassAncestry();
		$baseClass = $directAncestry[0];
		$relatedClasses[$baseClass] = $baseClass;
		$relatedClasses = array_merge($relatedClasses, $this->collectSubclassesFor($baseClass));
		return $relatedClasses;
	}
	
}

/**
 * Abstract base class for different DataObject cleaners.
 */
abstract class DataObjectOnDeleteDecorator_AbstractCleaner {
	
	/**
	 * @var integer
	 */
	protected $ID = null;
	
	/**
	 * @var DataObject
	 */
	protected $object = null;
	
	/**
	 * @param int $deletedID
	 * @param DataObject $object
	 */
	public function __construct($ID, DataObject $object) {
		$this->ID = (int)$ID;
		$this->object = $object;
	}
	
	/**
	 * Perform whatever cleaning is required.
	 */
	public abstract function clean();
	
}

/**
 * has_many <= has_one cleaner.
 */
class DataObjectOnDeleteDecorator_HasManyCleaner extends DataObjectOnDeleteDecorator_AbstractCleaner {
	
	/**
	 * Collect all DataObjects related to $this->object.
	 * 
	 * @param string $class
	 * @param string $classFieldName
	 * 
	 * @return null|DataObjectSet
	 */
	protected function collectDependentDataObjects($class, $classFieldName) {
		return DataObject::get($class, "\"{$classFieldName}\" = {$this->ID}");
	}
	
	/**
	 * @param string $ownerRelationName @see self::probeRelationClass()
	 * @param string $class @see self::probeRelationClass()
	 * @param string $classRelationName self::handleRelation()
	 * @param array $classHasOneOnDelete The on delete actions
	 * specified for $class.
	 */
	protected function handleDataObjects($ownerRelationName, $class, $classRelationName,
			array $classHasOneOnDelete) {
		$action = $classHasOneOnDelete[$classRelationName];
		$fieldName = "{$classRelationName}ID";
		$objects = $this->collectDependentDataObjects($class, $fieldName);
		if ($objects) foreach ($objects as $object) {
			if (preg_match('/^set\s+null$/i', trim($action))) {
				$object->$fieldName = 0;
				$object->write();
			} else if (strtolower(trim($action)) == 'delete') {
				$object->delete();
			} else {
				user_error("{$class} has an invalid action ({$action}) specified"
					. " for has_one_on_delete for relation {$classRelationName}.", E_USER_ERROR);
			}
		}
	}
	
	/**
	 * If the given $class has specified $class::$has_one_on_delete
	 * those rules will be used to handle all objects of $class
	 * directly linked to the owner.
	 * 
	 * @param string $ownerRelationName @see self::probeRelationClass()
	 * @param string $class @see self::probeRelationClass()
	 * @param string $classRelationName The name of a relation
	 * linking back to the owner.
	 */
	protected function handleRelation($ownerRelationName, $class, $classRelationName) {
		$classHasOneOnDelete = (array)Object::combined_static($class, 'has_one_on_delete');
		if (array_key_exists($classRelationName, $classHasOneOnDelete)) {
			if (Object::has_extension($class, 'Versioned')) {
				foreach (DataObjectOnDeleteDecorator::version_stages($this->object) as $stage) {
					$versionedReadingMode = Versioned::get_reading_mode();
					Versioned::reading_stage($stage);
					$this->handleDataObjects($ownerRelationName, $class, $classRelationName,
						$classHasOneOnDelete);
					Versioned::set_reading_mode($versionedReadingMode);
				}
			} else {
				$this->handleDataObjects($ownerRelationName, $class, $classRelationName,
					$classHasOneOnDelete);
			}
		}
	}
	
	/**
	 * Probe the given relation, it is necessary to find out
	 * which relations on $class which links back to the given
	 * $ownerRelationName, for each match self::handleRelation()
	 * is called to have it handled.
	 * 
	 * @param string $ownerRelationName The name of the current
	 * relation on the owner.
	 * @param string $class The class for $ownerRelationName.
	 */
	public function probeRelationClass($ownerRelationName, $class) {
		$ancestry = ClassInfo::ancestry($this->object->ClassName);
		foreach ((array)Object::combined_static($class, 'has_one') as $relationName => $relationClass) {
			if (in_array($relationClass, $ancestry))
				$this->handleRelation($ownerRelationName, $class, $relationName);
		}
	}
	
	public function clean() {
		$hasManyRelations = $this->object->has_many();
		foreach ($hasManyRelations as $relationName => $relationClass)
			$this->probeRelationClass($relationName, $relationClass, 'has_one');
	}
}

/**
 * many_many <=> belongs_many_many cleaner.
 */
class DataObjectOnDeleteDecorator_ManyManyCleaner extends DataObjectOnDeleteDecorator_AbstractCleaner {
	
	/**
	 * A list over intermediate tables which will be ignored
	 * when encountered.
	 * @var array
	 */
	protected static $ignored_tables = array();
	
	/**
	 * @param array $tables
	 * @see self::$ignored_tables
	 */
	public static function set_ignored_tables(array $tables) {
		self::$ignored_tables = $tables;
	}
	
	/**
	 * @return array
	 * @see self::$ignored_tables
	 */
	public static function get_ignored_tables() {
		return self::$ignored_tables;
	}
	
	/**
	 * Tables which should only be deleted from one side of the
	 * relation.
	 * @var array
	 */
	protected static $one_way_tables = array();
	
	/**
	 * @param array $tables
	 * @see self::$one_way_tables
	 */
	public static function set_one_way_tables(array $tables) {
		self::$one_way_tables = $tables;
	}
	
	/**
	 * @return array
	 * @see self::$one_way_tables
	 */
	public static function get_one_way_tables() {
		return self::$one_way_tables;
	}
	
	/**
	 * Clean the intermediate table from references to $this->ID.
	 */
	protected function handleRelation($class, $classRelationName) {
		$dbTables = DataObjectOnDeleteDecorator::get_db_tables();
		list($parentClass, $componentClass, $parentField, $componentField, $table) = $this->object->many_many($classRelationName);
		if (!in_array($table, self::get_ignored_tables()) && in_array(strtolower($table), $dbTables)) {
			$oneWayTables = self::get_one_way_tables();
			$oneWayTable = array_key_exists($table, $oneWayTables);
			if (!$oneWayTable || ($oneWayTable && $oneWayTables[$table] == $classRelationName)) {
				DB::query("DELETE FROM \"{$table}\" WHERE \"{$parentField}\" = {$this->ID}");
			}
		}
	}
	
	public function clean() {
		foreach (array('many_many', 'belongs_many_many') as $relationName) {
			foreach ((array)Object::uninherited_static($this->object->ClassName, $relationName) as $relationName => $relationClass) {
				$this->handleRelation($this->object->ClassName, $relationName);
			}
		}
	}
	
}

