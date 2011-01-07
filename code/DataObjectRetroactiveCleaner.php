<?php

/**
 * Copyright 2010 Charden Reklam Östersund AB (http://charden.se/)
 * Erik Edlund <erik@charden.se>
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
 * * Neither the name of Charden Reklam, nor the names of its contributors may be
 *   used to endorse or promote products derived from this software without specific
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
 * Perform retroactive cleaning for a DataObject class.
 * 
 * @see DataObjectRetroactiveCleanerTask
 */
class DataObjectRetroactiveCleaner extends Object {
	
	/**
	 * Maximum amount of time allowed for performing retroactive
	 * cleaning for one DataObject class.
	 * @var integer
	 */
	private static $time_limit = 120;
	
	/**
	 * @param integer $value
	 * @see self::$time_limit
	 */
	public static function set_time_limit($value) {
		self::$time_limit = (int)$value;
	}
	
	/**
	 * @return integer
	 * @see self::$time_limit
	 */
	public static function get_time_limit() {
		return self::$time_limit;
	}
	
	/**
	 * The name of the DataObject class being handled.
	 * @var string
	 */
	protected $className = null;
	
	/**
	 * The DataObject base class for the class being hanled.
	 * @var string
	 */
	protected $baseClassName = null;
	
	/**
	 * The table for the DataObject class being handled.
	 * @var string
	 */
	protected $table = null;
	
	/**
	 * Singleton instance of $this->className.
	 * @var DataObject
	 */
	protected $dataObject = null;
	
	/**
	 * Class parents for $this->className which have their own
	 * tables in the database.
	 * @var array
	 */
	protected $tableAncestors = array();
	
	/**
	 * All class parents for $this->className.
	 * @var array
	 */
	protected $allAncestors = array();
	
	/**
	 * Construct a new cleaner for the given DataObject class.
	 * 
	 * @param string $class
	 */
	public function __construct($class) {
		set_time_limit(self::get_time_limit());
		
		$this->className = $class;
		$this->dataObject = singleton($this->className);
		$this->baseClassName = ClassInfo::baseDataClass($this->className);
		$this->tableAncestors = ClassInfo::ancestry($this->className, true);
		$this->allAncestors = ClassInfo::ancestry($this->className, false);
		// Remove Object, ViewableData, DataObject
		$this->allAncestors = array_slice($this->allAncestors, 3, count($this->allAncestors) - 3, true);
		
		JanitorDebug::message("Class: {$this->className}", 'h3');
		JanitorDebug::message('Table ancestors: ' . var_export($this->tableAncestors, true), 'pre');
		JanitorDebug::message('All ancestors: ' . var_export($this->allAncestors, true), 'pre');
		
		// Select the table that we will be working with.
		$this->table = array_reverse($this->tableAncestors, false);
		$this->table = DataObject::has_own_table($this->className)? $this->className: array_shift($this->table);
		
		JanitorDebug::message("Selected table: {$this->table}", 'h4');
	}
	
	public function clean() {
		if (!in_array(strtolower($this->table), DataObjectOnDeleteDecorator::get_db_tables())) {
			JanitorDebug::message("{$this->table} does not exist in the database, aborting!", 'p', '');
			return false;
		}
		
		// Collect the row IDs for the given DataObject $class for
		// all possible stages.
		$classObjectIDs = array();
		$stages = $this->getDataObjectStages($this->dataObject);
		foreach ($stages as $stage) {
			$tablePostfix = $stage == 'Stage'? '': "_{$stage}";
			$query = "SELECT \"ID\" FROM \"{$this->table}{$tablePostfix}\" WHERE 1=1";
			$classObjectIDs = array_merge($classObjectIDs, DB::query($query)->column('ID'));
		}
		// The same ID may be present on multiple stages.
		$classObjectIDs = array_unique($classObjectIDs);
		
		JanitorDebug::message('Found table rows: ' . var_export($classObjectIDs, true), 'pre');
		
		foreach ($classObjectIDs as $classObjectID) {
			JanitorDebug::message("Working with row ID: {$classObjectID}", 'h4');
			$dataObject->ID = $classObjectID;
			// If we can get a DataObject from the base class and ID,
			// we can be sure that it exists in database as a base
			// class or one of its sub-classes.
			if ((bool)DataObject::get_by_id($this->baseClassName, $classObjectID)) {
				$this->handleDataObject($classObjectID);
			} else {
				$this->handleBrokenDataObject($classObjectID);
			}
			$this->dataObject->ID = 0;
		}
		JanitorDebug::message("Class: {$this->className}", 'h3');
		$this->handleBrokenManyManyRelations();
		return true;
	}
	
	/**
	 * Get the available stages for the given DataObject.
	 * 
	 * @param DataObject $dataObject
	 * 
	 * @return array
	 */
	public function getDataObjectStages(DataObject $dataObject) {
		return $dataObject->hasExtension('Versioned')?
			$dataObject->getStages():
			array('Stage');
	}
	
	/**
	 * Determine if a normal DataObject should be deleted due
	 * to broken has_one relations and has_one_on_delete rules.
	 * 
	 * @param integer $ID
	 * 
	 * @return boolean
	 */
	public function handleDataObject($ID) {
		JanitorDebug::message("handleDataObject({$ID})");
		$stages = $this->getDataObjectStages($this->dataObject);
		$baseDataObject = DataObject::get_by_id($this->baseClassName, $ID);
		if ($baseDataObject->ClassName != $this->dataObject->ClassName) {
			JanitorDebug::message("DataObject ClassName is \"{$baseDataObject->ClassName}\", aborting");
			return false;
		}
		JanitorDebug::message("Validating DataObject has_one relations");
		foreach ((array)$baseDataObject->has_one() as $relationName => $relationClass) {
			$relationID = $baseDataObject->{"{$relationName}ID"};
			if (!$relationID) {
				JanitorDebug::message("has_one relation \"{$relationName}\" is NULL, skipping", 'p', 'color:#6470ae');
				continue;
			}
			$relationObject = singleton($relationClass);
			$relationStages = $this->getDataObjectStages($relationObject);
			$relationBaseDataClass = ClassInfo::baseDataClass($relationClass);
			if ((bool)DataObject::get_by_id($relationBaseDataClass, $relationID)) {
				JanitorDebug::message("has_one relation \"{$relationName}\" connects to {$relationClass}#{$relationID}", 'p', 'color:#00c320');
				continue;
			}
			JanitorDebug::message("has_one relation \"{$relationName}\" => {$relationClass}#{$relationID} is broken", 'p', 'color:#ff0000');
			JanitorDebug::message("Performing retroactive cleaning for {$relationClass}#{$relationID}", 'p', 'color:#ff0000');
			$relationObject->ID = $relationID;
			$relationObject->onAfterDeleteCleaning();
		}
		return true;
	}
	
	/**
	 * Handle an orphaned row in the database and perform proper
	 * cleanup for it.
	 * 
	 * @param integer $ID
	 * 
	 * @return boolean
	 */
	public function handleBrokenDataObject($ID) {
		JanitorDebug::message("handleBrokenDataObject({$ID})");
		$stages = $this->getDataObjectStages($this->dataObject);
		foreach ($stages as $stage) {
			$tablePostfix = $stage == 'Stage'? '': "_{$stage}";
			$query = "SELECT \"ID\" FROM \"{$this->table}{$tablePostfix}\" WHERE \"ID\" = {$ID}";
			if (DB::query($query)->value()) {
				$query = "DELETE FROM \"{$this->table}{$tablePostfix}\" WHERE \"ID\" = {$ID}";
				JanitorDebug::message("Running query: {$query}", 'p', 'color:#ff0000');
				DB::query($query);
			}
		}
		
		// Since the DataObject has been deleted at some point we
		// should perform the after delete cleaning on the mock
		// DataObject to ensure that relations are handled properly.
		$this->dataObject->onAfterDeleteCleaning();
		
		return true;
	}
	
	/**
	 * Handle orphaned rows in intermediate many_many tables.
	 */
	public function handleBrokenManyManyRelations() {
		JanitorDebug::message("handleBrokenManyManyRelations()");
		$dbTables = DataObjectOnDeleteDecorator::get_db_tables();
		foreach ((array)$this->dataObject->many_many() as $relationName => $relationClass) {
			list($parentClass, $componentClass, $parentField, $componentField, $table) = $this->dataObject->many_many($relationName);
			if (!in_array($table, DataObjectOnDeleteDecorator_ManyManyCleaner::get_ignored_tables()) &&
				in_array(strtolower($table), $dbTables)) {
				$oneWayTables = DataObjectOnDeleteDecorator_ManyManyCleaner::get_one_way_tables();
				$oneWayTable = array_key_exists($table, $oneWayTables);
				if ($oneWayTable && $oneWayTables[$table] != $relationName) {
					JanitorDebug::message("Handling one-way table \"{$table}\" from \"{$relationName}\", aborting");
					continue;
				}
				$query = "SELECT \"{$parentField}\" FROM \"{$table}\" WHERE 1=1";
				$relations = DB::query($query)->column($parentField);
				foreach ((array)$relations as $ID) {
					$baseDataObject = DataObject::get_by_id($this->baseClassName, $ID);
					if ($baseDataObject)
						continue;
					$query = "DELETE FROM \"{$table}\" WHERE \"{$parentField}\" = {$ID}";
					JanitorDebug::message("Running query: {$query}", 'p', 'color:#ff0000');
					DB::query($query);
				}
			}
		}
	}
	
}

