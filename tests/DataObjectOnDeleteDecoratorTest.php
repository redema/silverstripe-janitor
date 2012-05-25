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
 * Test cases for common situations.
 */
class DataObjectOnDeleteDecoratorTest extends SapphireTest {
	
	/**
	 * Translatable is not actually required, but it does not
	 * hurt to have it enabled while running these tests.
	 */
	protected $requiredExtensions = array(
		'SiteTree' => array('Translatable')
	);
	
	public static $fixture_file = 'janitor/tests/DataObjectOnDeleteDecoratorTest.yml';
	
	private $translatableDefaultLocale = null;
	
	public function setUp() {
		parent::setUp();
		$this->translatableDefaultLocale = Translatable::default_locale();
		Translatable::set_default_locale('en_US');
	}
	
	public function tearDown() {
		parent::tearDown();
		Translatable::set_default_locale($this->translatableDefaultLocale);
	}
	
	/**
	 * Make sure that all tables are clean for the given $class,
	 * which needs to be Versioned.
	 * 
	 * Will fail unless DataObjectOnDeleteDecorator is
	 * configured to also clean the *_versions table.
	 * 
	 * @param string $class
	 * @param integer $ID
	 * @param boolean $assertThatIDExists
	 * 
	 * @see DataObjectOnDeleteDecorator::set_clean_versions_table()
	 */
	protected function assertVersionedTables($class, $ID, $assertThatIDExists = false) {
		// Assume that the Versioned patch has been applied.
		$assertFunction = $assertThatIDExists? 'assertTrue': 'assertFalse';
		$versioned = singleton($class)->getExtensionInstance('Versioned');
		$this->assertTrue(method_exists($versioned, 'getStages'));
		$tablePostfixes = $versioned->getStages();
		array_unshift($tablePostfixes, 'versions');
		// All tables should be clean, SilverStripe will normally
		// leave the *_versions table for versioned DataObjects.
		foreach ($tablePostfixes as $postfix) {
			$table = $postfix != 'Stage'? "{$class}_{$postfix}": $class;
			$column = $postfix != 'versions'? 'ID': 'RecordID';
			$this->$assertFunction((bool)DB::query("SELECT \"ID\" FROM \"{$table}\" WHERE \"{$column}\" = {$ID}")->value(),
				$assertThatIDExists? "Could not find a row for {$column} = {$ID} for {$table}":
				"Could find a row for {$column} = {$ID} for {$table}");
		}
	}
	
	public function testNormalSiteTreeDelete() {
		DataObjectOnDeleteDecorator::set_clean_versions_table(true);
		$page = new Page();
		$page->Title = 'Page';
		$page->write();
		$pageID = $page->ID;
		
		$page->delete();
		$this->assertVersionedTables('SiteTree', $pageID, false);
		
		DataObjectOnDeleteDecorator::set_clean_versions_table(false);
	}
	
	public function testNormalSiteTreeDeleteWithoutVersionsTableCleaning() {
		$page = new Page();
		$page->Title = 'Page';
		$page->write();
		$pageID = $page->ID;
		
		$page->delete();
		
		$query = "SELECT \"ID\" FROM \"SiteTree_versions\" WHERE \"RecordID\" = {$pageID}";
		$this->assertTrue((bool)DB::query($query)->value(),
			"No versions found for RecordID {$pageID} even though one should exist");
	}
	
	public function testVersionedSiteTreeDelete() {
		DataObjectOnDeleteDecorator::set_clean_versions_table(true);
		$page = new Page();
		$page->Title = 'Page';
		$page->write();
		$page->doPublish();
		$pageID = $page->ID;
		
		$page->deleteFromStage('Live');
		$page = DataObject::get_by_id('Page', $pageID);
		$page->delete();
		
		$this->assertVersionedTables('SiteTree', $pageID, false);
		
		DataObjectOnDeleteDecorator::set_clean_versions_table(false);
	}
	
	public function testVersionedSiteTreeDeleteWithDeletableHasOneRelation() {
		$page = new Page();
		$page->Title = 'Page';
		$page->write();
		$page->doPublish();
		$pageID = $page->ID;
		
		$comment = new PageComment();
		$comment->ParentID = $page->ID;
		$comment->write();
		$commentID = $comment->ID;
		
		$query = "SELECT \"ID\" FROM \"PageComment\" WHERE \"ID\" = {$commentID}";
		$page->deleteFromStage('Live');
		$page = DataObject::get_by_id('Page', $pageID);
		$this->assertTrue((bool)DB::query($query)->value(),
			"PageComment removed prematurely");
		$page->delete();
		$this->assertFalse((bool)DB::query($query)->value(),
			"PageComment not removed");
	}
	
	public function testDataObjectDeleteWithNullableHasOneRelation() {
		$page = new Page();
		$page->Title = 'Page';
		$page->write();
		$pageID = $page->ID;
		
		$author = new Member();
		$author->Email = 'email@example.com';
		$author->Password = 'hunter2';
		$author->write();
		$authorID = $author->ID;
		
		$comment = new PageComment();
		$comment->ParentID = $page->ID;
		$comment->AuthorID = $author->ID;
		$comment->write();
		$commentID = $comment->ID;
		
		$author->delete();
		
		$comment = DataObject::get_by_id('PageComment', $commentID);
		$this->assertInstanceOf('PageComment', $comment);
		$this->assertFalse((bool)$comment->AuthorID,
			"PageComment AuthorID not set to null when the author was deleted");
	}
	
	public function testVersionedSiteTreeDeleteWithLinkTracking() {
		// Test deleting the many_many side (with a one-way table):
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
		$this->assertTrue((bool)DB::query($query)->value(),
			"Expected many_many connection for SiteTree_LinkTracking not found");
		
		$page2->deleteFromStage('Live');
		$page1 = DataObject::get_by_id('Page', $page1ID);
		$this->assertTrue((bool)DB::query($query)->value(),
			"Expected many_many connection for SiteTree_LinkTracking not found");
		$page2 = DataObject::get_by_id('Page', $page2ID);
		$page2->delete();
		$page1 = DataObject::get_by_id('Page', $page1ID);
		$this->assertFalse((bool)DB::query($query)->value(),
			"many_many table SiteTree_LinkTracking not cleaned properly");
		
		// We can not check if deleting the belongs_many_many side
		// behaves correctly here, SiteTree_LinkTracking is a one-way
		// table to ensure that it works as expected.
	}
	
	public function testVersionedSiteTreeDeleteWithImageTracking() {
		// Assume that file tracking works. (If it does not, other
		// tests will fail as well.)
		$file = new File();
		$file->Filename = 'test-file.pdf';
		$file->write();
		$fileID = $file->ID;
		
		$page = new Page();
		$page->Title = 'Page';
		$page->Content = '<a href="assets/test-file.pdf">File</a>';
		$page->write();
		$page->doPublish();
		$pageID = $page->ID;
		
		$page->deleteFromStage('Live');
		$page = DataObject::get_by_id('Page', $pageID);
		$page->delete();
		
		$this->assertFalse((bool)DB::query("SELECT \"ID\" FROM \"SiteTree_ImageTracking\" WHERE \"SiteTreeID\" = {$pageID}")->value(),
			"many_many table SiteTree_ImageTracking not cleaned properly");
	}
	
	public function testDataObjectDeleteWithManyManyRelations() {
		// There are other tests dealing with deleting the many_many
		// side of many_many <=> belongs_many_many relations, this
		// time we need to make sure that deleting the belongs_many_many
		// side gives expected results.
		$classA = 'DataObjectOnDeleteDecoratorTest_ManyMany_A';
		$manyManyA = new $classA();
		$manyManyA->write();
		$manyManyAID = $manyManyA->ID;
		
		$classB = 'DataObjectOnDeleteDecoratorTest_ManyMany_B';
		$manyManyB = new $classB();
		$manyManyB->write();
		$manyManyBID = $manyManyB->ID;
		
		$manyManyA->ManyMany_Bs()->add($manyManyBID);
		
		$query = "SELECT \"ID\" FROM \"{$classA}_ManyMany_Bs\" WHERE \"{$classA}ID\" = {$manyManyAID}";
		$this->assertTrue((bool)DB::query($query)->value(),
			"many_many table {$classA}_ManyMany_Bs cleaned prematurely");
		$manyManyB->delete();
		$this->assertFalse((bool)DB::query($query)->value(),
			"many_many table {$classA}_ManyMany_Bs not cleaned properly");
	}
	
	public function testNormalSiteTreeDeleteWithDirtyRelatedTables() {
		DataObjectOnDeleteDecorator::set_clean_versions_table(true);
		$page = new VirtualPage();
		$page->Title = 'Page';
		$page->write();
		$pageID = $page->ID;
		
		$page = $page->newClassInstance('RedirectorPage');
		$page->write();
		$page = DataObject::get_by_id('RedirectorPage', $pageID);
		$this->assertInstanceOf('RedirectorPage', $page);
		
		$this->assertTrue((bool)DB::query("SELECT \"ID\" FROM \"VirtualPage\" WHERE \"ID\" = {$pageID}")->value(),
			"VirtualPage row not found");
		$this->assertTrue((bool)DB::query("SELECT \"ID\" FROM \"RedirectorPage\" WHERE \"ID\" = {$pageID}")->value(),
			"RedirectorPage row not found");
		
		$page->delete();
		
		$this->assertVersionedTables('VirtualPage', $pageID, false);
		$this->assertVersionedTables('RedirectorPage', $pageID, false);
		
		DataObjectOnDeleteDecorator::set_clean_versions_table(false);
	}
	
	public function testVersionedSiteTreeDeleteWithDeletableVersionedHasOneRelation() {
		DataObjectOnDeleteDecorator::set_clean_versions_table(true);
		
		$masterPage = DataObject::get_one('DataObjectOnDeleteDecoratorTest_MasterPage', "\"Title\" = 'MasterPage'");
		$this->assertInstanceOf('DataObjectOnDeleteDecoratorTest_MasterPage', $masterPage);
		$masterPageID = $masterPage->ID;
		
		$subPage = DataObject::get_one('DataObjectOnDeleteDecoratorTest_SubPage', "\"Title\" = 'SubPage'");
		$this->assertInstanceOf('DataObjectOnDeleteDecoratorTest_SubPage', $subPage);
		$subPageID = $subPage->ID;
		$this->assertEquals($masterPage->ID, $subPage->MasterPageID, "Something is wrong with the fixture");
		$subPage->doPublish();
		
		$masterPage->delete();
		
		$this->assertVersionedTables('SiteTree', $masterPageID, false);
		$this->assertVersionedTables('DataObjectOnDeleteDecoratorTest_SubPage', $subPageID, false);
		
		DataObjectOnDeleteDecorator::set_clean_versions_table(false);
	}
	
	public function testVersionedDataObjectDeleteWithNonStandardStages() {
		DataObjectOnDeleteDecorator::set_clean_versions_table(true);
		
		$class = 'DataObjectOnDeleteDecoratorTest_SpecialStages';
		$specialStages = new $class();
		$specialStages->Name = 'Test';
		$specialStages->write();
		$specialStagesID = $specialStages->ID;
		
		$specialStages->publish('Stage', 'Intermediate');
		$specialStages->publish('Intermediate', 'Live');
		
		$this->assertVersionedTables($class, $specialStagesID, true);
		
		$specialStages->deleteFromStage('Live');
		$this->assertTrue((bool)DB::query("SELECT \"ID\" FROM \"{$class}\" WHERE \"ID\" = {$specialStagesID}")->value(),
			"Stage table cleaned prematurely");
		$this->assertTrue((bool)DB::query("SELECT \"ID\" FROM \"{$class}_Intermediate\" WHERE \"ID\" = {$specialStagesID}")->value(),
			"Intermediate table cleaned prematurely");
		$specialStages = DataObject::get_by_id($class, $specialStagesID);
		$specialStages->deleteFromStage('Intermediate');
		$this->assertTrue((bool)DB::query("SELECT \"ID\" FROM \"{$class}\" WHERE \"ID\" = {$specialStagesID}")->value(),
			"Intermediate table cleaned prematurely");
		$specialStages = DataObject::get_by_id($class, $specialStagesID);
		$specialStages->delete();
		
		$this->assertVersionedTables($class, $specialStagesID, false);
		
		DataObjectOnDeleteDecorator::set_clean_versions_table(false);
	}
	
	/**
	 * @see self::testVersionedSiteTreeDeleteWithDeletableVersionedHasOneRelation()
	 */
	public function testVersionedSiteTreeDeleteWithDeletableVersionedHasOneRelationFromNewClassInstance() {
		// Make sure that has_many <= has_one relations are cleaned
		// for related DataObject classes even if the class type
		// being deleted does not have the relations.
		DataObjectOnDeleteDecorator::set_clean_versions_table(true);
		
		$masterPage = DataObject::get_one('DataObjectOnDeleteDecoratorTest_MasterPage', "\"Title\" = 'MasterPage'");
		$this->assertInstanceOf('DataObjectOnDeleteDecoratorTest_MasterPage', $masterPage);
		$masterPageID = $masterPage->ID;
		
		$subPage = DataObject::get_one('DataObjectOnDeleteDecoratorTest_SubPage', "\"Title\" = 'SubPage'");
		$this->assertInstanceOf('DataObjectOnDeleteDecoratorTest_SubPage', $subPage);
		$subPageID = $subPage->ID;
		$this->assertEquals($masterPage->ID, $subPage->MasterPageID, "Something is wrong with the fixture");
		$subPage->doPublish();
		
		$masterPage = $masterPage->newClassInstance('VirtualPage');
		$masterPage->write();
		$masterPage->delete();
		
		$this->assertVersionedTables('SiteTree', $masterPageID, false);
		$this->assertVersionedTables('DataObjectOnDeleteDecoratorTest_SubPage', $subPageID, false);
		
		DataObjectOnDeleteDecorator::set_clean_versions_table(false);
	}
	
	public function testDataObjectDeleteWithManyManyRelationsFromNewClassInstance() {
		// Make sure that many_many <=> belongs_many_many relations
		// are cleaned for related DataObject classes even if the
		// class type being deleted does not have the relations.
		$classA = 'DataObjectOnDeleteDecoratorTest_ManyMany_A';
		$manyManyA = new $classA();
		$manyManyA->write();
		$manyManyAID = $manyManyA->ID;
		
		$classB = 'DataObjectOnDeleteDecoratorTest_ManyMany_B';
		$manyManyB = new $classB();
		$manyManyB->write();
		$manyManyBID = $manyManyB->ID;
		
		$manyManyA->ManyMany_Bs()->add($manyManyBID);
		
		$query = "SELECT \"ID\" FROM \"{$classA}_ManyMany_Bs\" WHERE \"{$classA}ID\" = {$manyManyAID}";
		$this->assertTrue((bool)DB::query($query)->value(),
			"many_many table {$classA}_ManyMany_Bs cleaned prematurely");
		$manyManyA = $manyManyA->newClassInstance('DataObjectOnDeleteDecoratorTest_ManyMany_C');
		$manyManyA->write();
		$manyManyA->delete();
		$this->assertFalse((bool)DB::query($query)->value(),
			"many_many table {$classA}_ManyMany_Bs not cleaned properly");
	}
	
	public function testMemberSpecialCleanup() {
		// Member relations are configured to be handled in the last
		// second by JanitorMemberDecorator, be sure that the hack
		// works as expected.
		$member = new Member();
		$member->Email = 'email@example.com';
		$member->Password = 'hunter2';
		$member->write();
		$memberID = $member->ID;
		$member->Password = 'hunter3';
		$member->write();
		
		$this->assertEquals(2, DataObject::get('MemberPassword', "\"MemberID\" = {$memberID}")->Count(),
			"MemberPassword cleaned prematurely");
		$member->delete();
		$this->assertFalse((bool)DataObject::get('MemberPassword', "\"MemberID\" = {$memberID}"),
			"MemberPassword not cleaned propely");
	}
	
}

/**
 * Test implementation details.
 * @ignore
 * #@+
 */
class DataObjectOnDeleteDecoratorTest_MasterPage extends Page {
	public static $has_many = array(
		'SubPages' => 'DataObjectOnDeleteDecoratorTest_SubPage'
	);
}

class DataObjectOnDeleteDecoratorTest_SubPage extends Page {
	public static $has_one = array(
		'MasterPage' => 'DataObjectOnDeleteDecoratorTest_MasterPage'
	);
	public static $has_one_on_delete = array(
		'MasterPage' => 'delete'
	);
}

class DataObjectOnDeleteDecoratorTest_SpecialStages extends DataObject {
	public static $db = array(
		'Name' => 'Varchar(16)'
	);
	public static $extensions = array(
		"Versioned('Stage', 'Intermediate', 'Live')"
	);
}

class DataObjectOnDeleteDecoratorTest_ManyMany_Base extends DataObject {
	public static $db = array(
		'Dummy' => 'Varchar(16)'
	);
}

class DataObjectOnDeleteDecoratorTest_ManyMany_A extends DataObjectOnDeleteDecoratorTest_ManyMany_Base {
	public static $db = array(
		'Name' => 'Varchar(16)'
	);
	public static $many_many = array(
		'ManyMany_Bs' => 'DataObjectOnDeleteDecoratorTest_ManyMany_B'
	);
}

class DataObjectOnDeleteDecoratorTest_ManyMany_B extends DataObjectOnDeleteDecoratorTest_ManyMany_Base {
	public static $db = array(
		'Name' => 'Varchar(16)'
	);
	public static $belongs_many_many = array(
		'ManyMany_As' => 'DataObjectOnDeleteDecoratorTest_ManyMany_A'
	);
}

class DataObjectOnDeleteDecoratorTest_ManyMany_C extends DataObjectOnDeleteDecoratorTest_ManyMany_Base {
	public static $db = array(
		'ForceNewTable' => 'Varchar(16)'
	);
}
/**
 * #@-
 */

