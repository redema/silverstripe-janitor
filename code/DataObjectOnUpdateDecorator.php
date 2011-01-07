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
 * <h1>Summary</h1>
 * 
 * Decorates Versioned to provide stage handling for versioned
 * DataObjects when they are updated. It is currently only
 * useful when dealing with versioned DataObjects (and
 * SiteTree subclasses in particular).
 * 
 * <code>
 * class Page extends SiteTree {
 *     public static $has_many = array(
 *         'Quotes' => 'Quote'
 *     );
 * }
 * 
 * class Quote extends DataObject {
 *     public static $has_one = array(
 *         'Page' => 'Page'
 *     );
 *     public static $has_one_on_update = array(
 *         'Page' => true
 *     );
 *     public static $extensions = array(
 *         "Versioned('Stage', 'Live')"
 *     );
 * }
 * </code>
 */
class DataObjectOnUpdateDecorator extends DataObjectDecorator {
	
	/**
	 * Collect all components (has_one/has_many/many_many) from
	 * extension instances held by $this->owner. This is necessary
	 * to properly deal with DataObjectDecorators.
	 * 
	 * @param string $name has_one|has_many|many_many
	 * 
	 * @return array
	 */
	public function getExtensionComponents($name) {
		$components = array();
		$extensions = $this->owner->getExtensionInstances();
		if ($extensions) foreach ($extensions as $extension) {
			$extension->setOwner($this->owner);
			$extraStatics = $extension->extraStatics();
			if (is_array($extraStatics) && array_key_exists($name, $extraStatics)) {
				$components = array_merge($components, $extraStatics[$name]);
			}
			$extension->clearOwner();
		}
		return $components;
	}
	
	/**
	 * Get all updatable relations from $this->owner.
	 * 
	 * @return array
	 */
	protected function updatableRelations() {
		$updatableRelations = array();
		foreach (array_merge($this->owner->has_many(), $this->getExtensionComponents('has_many')) as
				$hasManyRelation => $hasManyClass) {
			$hasOneOnUpdate = (array)Object::combined_static($hasManyClass, 'has_one_on_update');
			foreach ($hasOneOnUpdate as $onUpdateRelation => $process) {
				$hasOneClass = singleton($hasManyClass)->has_one($onUpdateRelation);
				if ($hasOneClass == $this->owner->ClassName && $process)
					$updatableRelations[$hasManyRelation] = array(
						$hasManyClass,
						$onUpdateRelation
					);
			}
		}
		return $updatableRelations;
	}
	
	/**
	 * Make sure that $member is an object (unless the given ID
	 * is invalid).
	 * 
	 * @param int|Member
	 * 
	 * @return false|Member
	 */
	protected function memberToMemberObject($member) {
		return is_numeric($member)? DataObject::get_by_id('Member',
			$member): $member;
	}
	
	public function onBeforeVersionedExtensionPublish($fromStage, $toStage, $createNewVersion) {
	}
	
	/**
	 * Called when Versioned::publish(...) is called.
	 * 
	 * @param string $fromStage
	 * @param string $toStage
	 * @param boolean $createNewVersion
	 */
	public function onAfterVersionedExtensionPublish($fromStage, $toStage, $createNewVersion) {
		foreach ($this->updatableRelations() as $relationName => $relationData) {
			list($class, $field) = $relationData;
			$liveDataObjects = Versioned::get_by_stage($class, $toStage,
				"\"{$class}\".\"{$field}ID\" = {$this->owner->ID}");
			if ($liveDataObjects) foreach ($liveDataObjects as $object) {
				$object->deleteFromStage($toStage);
			}
			$stageDataObjects = $this->owner->$relationName();
			if ($stageDataObjects) foreach ($stageDataObjects as $object) {
				$object->publish($fromStage, $toStage, $createNewVersion);
			}
		}
	}
	
	public function onBeforeVersionedExtensionDeleteFromStage($stage) {
	}
	
	/**
	 * Called when Versioned::deleteFromStage(...) is called.
	 * 
	 * @param string $stage
	 * @param null|DataObject $result
	 */
	public function onAfterVersionedExtensionDeleteFromStage($stage, $result) {
		foreach ($this->updatableRelations() as $relationName => $relationData) {
			$this->owner->ID = $this->owner->OldID;
			$stageDataObjects = $this->owner->$relationName();
			if ($stageDataObjects) foreach ($stageDataObjects as $object) {
				$object->deleteFromStage($stage);
			}
			$this->owner->ID = 0;
		}
	}
	
	/**
	 * Intenionally unimplemented, use DataObject::write() with
	 * $writeComponents=true instead. The solution is not perfect,
	 * but there is no way to workaround Sapphires component
	 * cache in this case.
	 */
	public function onAfterWrite() {
		parent::onAfterWrite();
	}
	
	/**
	 * Ignored for now.
	 * #@+
	 */
	public function canCreate($member) {
		$member = $this->memberToMemberObject($member);
		return null;
	}
	
	public function canEdit($member) {
		$member = $this->memberToMemberObject($member);
		return null;
	}
	
	public function canPublish($member) {
		$member = $this->memberToMemberObject($member);
		return null;
	}
	
	public function canDeleteFromLive($member) {
		$member = $this->memberToMemberObject($member);
		return null;
	}
	
	public function canDelete($member) {
		$member = $this->memberToMemberObject($member);
		return null;
	}
	/**
	 * #@-
	 */
	
}

