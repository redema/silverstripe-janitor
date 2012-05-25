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
 * <h1>DISCLAIMER</h1>
 * 
 * Here be dragons. No, seriously. ALWAYS have a database
 * backup before running this task, so that you can easily
 * rollback if it destroys your database.
 * 
 * <h1>Summary</h1>
 * 
 * DataObjectOnDeleteDecorator can only deal with
 * DataObjects which are deleted while it is active, this
 * is not so useful for old SilverStripe installations and
 * databases which has been running for a while and surely
 * have accumulated a lot of junk rows. This task will
 * amend that problem by providing retroactive cleaning
 * of the database.
 * 
 * @see DataObjectRetroactiveCleaner
 */
class DataObjectRetroactiveCleanerTask extends BuildTask {
	
	/**
	 * @var string
	 */
	protected $title = "Clean Up Database";
	
	/**
	 * @var string
	 */
	protected $description = "
Find and delete unnecessary rows in the database by looking
for partially deleted DataObjects, by searching for broken
has_one relations where has_one_on_delete has been specified
and by cleaning intermediate many_many tables with broken
relations.
";
	
	/**
	 * @var string
	 */
	private $backupPath = null;
	
	/**
	 * @return string
	 */
	public function getBackupPath() {
		return $this->backupPath;
	}
	
	/**
	 * Perform retroactive DataObject cleaning.
	 * 
	 * @param SS_HTTPRequest $request
	 */
	public function run($request) {
		if (!Object::get_static('SapphireTest', 'is_running_test')) {
			JanitorDebug::set_verbose(true);
		}
		if (JanitorDBP::available()) {
			$this->backupPath = JanitorDBP::backup_database();
		}
		$dataObjectSubClasses = (array)ClassInfo::subclassesFor('DataObject');
		// Remove DataObject
		array_shift($dataObjectSubClasses);
		foreach ($dataObjectSubClasses as $class) {
			$retroactiveCleaner = new DataObjectRetroactiveCleaner($class);
			$retroactiveCleaner->clean();
		}
	}
	
	public function deleteBackup() {
		if ($this->backupPath && file_exists($this->backupPath)) {
			unlink($this->backupPath);
		}
	}
	
}

