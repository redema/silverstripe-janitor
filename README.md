# SilverStripe janitor Module

The way SilverStripe handles deletion of DataObjects leaves some things to be
desired since data which safely could have been discarded is often left in the
database. Two examples of this is the intermediate tables for many_many
relations and "related" tables for a DataObject (for example, if you create a
RedirectorPage and then change it to a VirtualPage and delete the VirtualPage,
junk will be left in the RedirectorPage table).

There is also a lack of a handy mechanism to specify what happens to has_many
<=> has_one relations when the has_many-side of the relation is deleted.

This module tries to provide means to deal with these problems by providing
custom cleaning of tables and relations on the onAfterDelete-event for
DataObjects.

## Maintainer Contact

Charden Reklam (charden) <http://charden.se/>

Author: Erik Edlund <erik@charden.se>

## Requirements

 * PHP: 5.2.4+ minimum.
 * Database: Only tested with MySQL 5 and SQLite3, but might work with other
   databases as well.
 * SilverStripe: 2.4.0 minimum (previous versions has never been tested).

## Installation Instructions

 * Place this directory in the root of your SilverStripe installation. Make sure
   that the folder is named "janitor" if you are planning to run the unit tests.

 * Patch the Versioned decorator using janitor/patch/Versioned.diff.
   See Known issues for more information.

 * Visit http://www.yoursite.example.com/dev/build?flush=all to rebuild the
   manifest.

 * Start using the provided features to handle DataObject relations.

 * Optionally: Run DataObjectRetroactiveCleanerTask to perform retroactive
   cleaning of your SilverStripe/Sapphire managed database. (NEVER do this
   unless you have a very recent backup of your database, you have been warned.)

## Usage Overview

If you have a has_many-has_one relation between two DataObjects

    class Parent extends DataObject {
        public static $has_many = array('Children' => 'Child');
    }
    
    class Child extends DataObject {
        public static $has_one = array('Parent' => 'Parent');
        public static $has_one_on_delete = array('Parent' => 'delete');
    }

all Children for a Parent will be deleted when their Parent is deleted. By
changing from "delete” to "set null” as the value for the "Parent” key in
$has_one_on_delete ParentID would be set to 0 for all children when Parent is
deleted instead.

This is a rough equivalence for using foreign key referential actions in SQL:

FOREIGN KEY(ParentID) REFERENCES Parent(ID) ON DELETE CASCADE

The advantage of handling this through PHP and SilverStripes ORM as Janitor
does, is that any cleanup code or restrictions in place for the DataObject being
deleted is run.

Janitor will also handle Versioned DataObjects in order to make sure that
cleaning is only performed when the DataObject has been deleted from all stages
and that cascading deletes handles all stages for Versioned DataObjects (the
reason why Versioned must be patched in order to use Janitor).

Another feature is that it cleans all tables in which the current DataObject
could have saved data. If you change type of a DataObject and then delete it

    $page = new RedirectorPage();
    $page->write();
    $page = $page->newClassInstance('VirtualPage');
    $page->write();
    $page->delete();

the row left in RedirectorPage will be deleted.

Combine the first example with the second

    $obj = DataObject::get_one('Parent', ...); // Assume it has children.
    $obj = $parent->newClassInstance('OtherObjectWithTheSameBaseClass');
    $obj->write();
    $obj->delete();

and find that all Children for the parent are deleted.

many_many-belong_many_many relations are also handled, references to deleted
objects are simply deleted from the intermediate database table.

More detailed documentation is available as source code comments in the module.
\_config.php and modules/\*/\_config.php contains some default settings which
should probably be looked over before the module is used. These defaults also
serves as usage examples.

## Known issues

 * In order to handle Versioned DataObjects Janitor needs to determine which
   stages are available. There is however no way to determine this since the
   Versioned decorator does not provide a get function to access the stages
   it was initialized with. The best way to deal with this is to apply the
   Versioned patch provided with this module. it simply adds a get function.
   To do this (assuming you are a Linux user), navigate to the root directory
   of the SilverStripe installation and run:
   
   $ patch -p1 < janitor/patch/Versioned.diff
   
   and you are good to go.

## Third party modules

Janitor will detect and automatically define has_one cleaning rules for  the
following SilverStripe modules:

 * blog
 * forum
 * userforms

If DBPlumber is installed, Janitor can be configured to backup the database
automatically before DataObjectRetroactiveCleanerTask is run, use
JanitorDBP::set_sql_file_path() to specify where the backup file should be
placed (preferably outside the web root).

