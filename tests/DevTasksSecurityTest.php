<?php

class DevTasksSecurityTest extends FunctionalTest {
	
	public static $fixture_file = 'janitor/tests/DevTasksSecurityTest.yml';
	
	/**
	 * Since DataObjectRetroactiveCleanerTask can be destructive,
	 * make sure that only admins can run it.
	 */
	public function testAccessRestrictionWithNonAdminMember() {
		$devServers = Object::combined_static('Director', 'dev_servers');
		Director::set_dev_servers(array('example.com'));
		$response = $this->get("dev/tasks/DataObjectRetroactiveCleanerTask");
		
		$selector = '#MemberLoginForm_LoginForm';
		$this->assertTrue((bool)$this->cssParser()->getBySelector($selector),
			'Non admin members can run DataObjectRetroactiveCleanerTask.');
		
		Director::set_dev_servers($devServers);
	}
	
}
