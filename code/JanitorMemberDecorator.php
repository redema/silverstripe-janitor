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
 * <h1>This is a hack</h1>
 * 
 * Member requires special attention and handling.
 */
class JanitorMemberDecorator extends DataObjectDecorator {
	
	/**
	 * List over psuedo has_many relations for Member which may
	 * only be used when a DataObject is actually deleted.
	 * @var array
	 * @see self::onBeforeDelete()
	 * @see self::onAfterDelete()
	 */
	private static $psuedo_has_many = array();
	
	/**
	 * @param string $name
	 * @param string $class
	 * 
	 * @see self::$psuedo_has_many
	 */
	public static function add_psuedo_has_many($name, $class) {
		self::$psuedo_has_many[$name] = $class;
	}
	
	/**
	 * @param array $hasManyRelations
	 * 
	 * @see self::$psuedo_has_many
	 */
	public static function set_psuedo_has_many(array $hasManyRelations) {
		self::$psuedo_has_many = $hasManyRelations;
	}
	
	/**
	 * @return array
	 * 
	 * @see self::$psuedo_has_many
	 */
	public static function get_psuedo_has_many() {
		return self::$psuedo_has_many;
	}
	
	/**
	 * Using add_static_var from _config.php to heal the broken
	 * has_many <=> has_one relations for Member will cause
	 * problems with the Member edit form used by SecurityAdmin
	 * (where the fixed relationships will be improperly scaffolded).
	 * By not fixing the relations until the last possible second
	 * we can pretend that everything is fine.
	 */
	public function onBeforeDelete() {
		parent::onBeforeDelete();
		Object::set_static('Member', 'has_many', array_merge(
			self::get_psuedo_has_many(),
			Object::get_static('Member', 'has_many', true)));
	}
	
	/**
	 * Restore everything that $this->onBeforeDelete() just did.
	 * 
	 * It is very important that this callback is run after
	 * DataObjectOnDeleteDecorator::onAfterDelete(), this
	 * is currently ensured by the way Object handles Extensions
	 * (see Object::__construct()).
	 */
	public function onAfterDelete() {
		$memberHasMany = Object::get_static('Member', 'has_many', true);
		foreach (self::get_psuedo_has_many() as $name => $class) {
			if (isset($memberHasMany[$name]))
				unset($memberHasMany[$name]);
	    }
		Object::set_static('Member', 'has_many', $memberHasMany);
	}
	
}

