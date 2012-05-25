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
 * Common test cases.
 */
class DataObjectRetroactiveCleanerTaskTest extends SapphireTest {
	
	public static $fixture_file = 'janitor/tests/DataObjectRetroactiveCleanerTaskTest.yml';
	
	public function setUp() {
		parent::setUp();
		$now = date('Y-m-d_His');
		$unique = mt_rand();
		$sqlFilePath = ASSETS_PATH . "/JanitorDatabaseBackup_{$now}_{$unique}.sql";
		JanitorDBP::set_sql_file_path($sqlFilePath);
	}
	
	public function testWithOneOrphanedRow() {
		DataObjectOnDeleteDecorator::set_disabled(true);
		//JanitorDebug::set_verbose(true);
		$page = new VirtualPage();
		$page->Title = 'Page1';
		$page->write();
		$pageID = $page->ID;
		
		$page = $page->newClassInstance('RedirectorPage');
		$page->write();
		$page = DataObject::get_by_id('RedirectorPage', $pageID);
		$this->assertInstanceOf('RedirectorPage', $page);
		
		$page->delete();
		
		DataObjectOnDeleteDecorator::set_disabled(false);
		$task = new DataObjectRetroactiveCleanerTask();
		$task->run(null);
		$task->deleteBackup();
		
		$this->assertFalse((bool)DB::query("SELECT \"ID\" FROM \"VirtualPage\" WHERE \"ID\" = {$pageID}")->value(),
			"VirtualPage not cleaned properly (retroactively)");
		JanitorDebug::set_verbose(false);
	}
	
	public function testWithOrphanedHasManyRelations() {
		DataObjectOnDeleteDecorator::set_disabled(true);
		//JanitorDebug::set_verbose(true);
		
		$page = new Page();
		$page->Title = 'Page1';
		$page->write();
		$pageID = $page->ID;
		
		for ($i = 0; $i < 5; ++$i) {
			$comment = new PageComment();
			$comment->Name = "Snowball {$i}";
			$comment->Comment = "Comment #{$i}!";
			$comment->ParentID = $pageID;
			$comment->write();
		}
		
		$page->delete();
		
		DataObjectOnDeleteDecorator::set_disabled(false);
		$task = new DataObjectRetroactiveCleanerTask();
		$task->run(null);
		$task->deleteBackup();
		
		$this->assertFalse((bool)DataObject::get('PageComment', "ParentID = {$pageID}"),
			"PageComment not cleaned properly (retroactively)");
		JanitorDebug::set_verbose(false);
	}
	
	public function testWithOrphanedManyManyRelations() {
		DataObjectOnDeleteDecorator::set_disabled(true);
		//JanitorDebug::set_verbose(true);
		
		$page1 = new Page();
		$page1->write();
		$page1->doPublish();
		$page1ID = $page1->ID;
		
		$page2 = new Page();
		$page2->Content .= "<p><a href=\"[sitetree_link id={$page1->ID}]\">page1 link</a></p>";
		$page2->write();
		$page2->doPublish();
		$page2ID = $page2->ID;
		
		$query = "SELECT \"ID\" FROM \"SiteTree_LinkTracking\" WHERE \"SiteTreeID\" = $page2ID AND \"ChildID\" = $page1ID";
		
		$page2->deleteFromStage('Live');
		$page2 = DataObject::get_by_id('Page', $page2ID);
		$page2->delete();
		
		$this->assertTrue((bool)DB::query($query)->value(),
			"many_many table SiteTree_LinkTracking cleaned prematurely (possibly due to SilverStripe core changes)");
		
		DataObjectOnDeleteDecorator::set_disabled(false);
		$task = new DataObjectRetroactiveCleanerTask();
		$task->run(null);
		$task->deleteBackup();
		
		$this->assertFalse((bool)DB::query($query)->value(),
			"many_many table SiteTree_LinkTracking not cleaned properly (retroactively)");
		JanitorDebug::set_verbose(false);
	}
	
	public function testDBPlumberIntegration() {
		if (!JanitorDBP::available()) {
			// When DB Plumber is not available, consider this test
			// passed.
			return;
		}
		$task = new DataObjectRetroactiveCleanerTask();
		$task->run(null);
		$this->assertFileExists($task->getBackupPath(), 'The database backup file does not exist');
		
		$task->deleteBackup();
		$this->assertFileNotExists($task->getBackupPath(), 'The database backup was not deleted');
	}
	
}

