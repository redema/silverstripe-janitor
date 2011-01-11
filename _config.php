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

// Make sure that Versioned is patched.
if (!method_exists('Versioned', 'getStages')) {
	trigger_error('janitor: DataObjectDecorator "Versioned" is unpatched.'
		. ' See README.md, "Known issues", for more information.', E_USER_ERROR);
}

Object::add_extension('DataObject', 'DataObjectOnDeleteDecorator');
Object::add_extension('Member', 'JanitorMemberDecorator');

JanitorMemberDecorator::add_psuedo_has_many('Janitor_MemberPasswords', 'MemberPassword');
JanitorMemberDecorator::add_psuedo_has_many('Janitor_PageComments', 'PageComment');

// Comments without an associated page can be removed since
// their context is lost.
Object::add_static_var('PageComment', 'has_one_on_delete', array(
	'Parent' => 'delete',
	'Author' => 'set null'
));

// Deleted members do not need passwords.
Object::add_static_var('MemberPassword', 'has_one_on_delete', array(
	'Member' => 'delete'
));

// Complete the SiteTree <=> Group many_many relationship.
Object::add_static_var('Group', 'belongs_many_many', array(
	'Janitor_ViewerSiteTrees' => 'SiteTree',
	'Janitor_EditorSiteTrees' => 'SiteTree'
));

// These intermediate tables must only be cleaned if the
// "correct" side of the relation is being deleted to prevent
// the cleaning from breaking SilverStripe functionality.
DataObjectOnDeleteDecorator_ManyManyCleaner::set_one_way_tables(array_merge(
	array(
		// LinkTracking can only be cleaned when the deletion takes
		// place at the many_many side of relation. The relation
		// must be allowed to break in certain Versioned situations.
		'SiteTree_LinkTracking' => 'LinkTracking',
		// Can only be cleaned when the SiteTree side of the relation
		// is deleted, since the relation must be allowed to break.
		'SiteTree_ImageTracking' => 'ImageTracking'
	), DataObjectOnDeleteDecorator_ManyManyCleaner::get_one_way_tables()
));

if (class_exists('UserDefinedForm')) require_once 'modules/userforms/_config.php';
if (class_exists('BlogEntry')) require_once 'modules/blog/_config.php';
if (class_exists('Forum')) require_once 'modules/forum/_config.php';

