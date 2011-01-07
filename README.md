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

## Documentation

The only currently available documentation is the doctags available in the
module. Take a look at
 * _config.php
 * code/DataObjectOnDeleteDecorator.php
to get started. _config.php contains some default settings which should
probably be looked over before the module is used. These defaults also serves
as examples.

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

Please refer to the doctag documentation.

## Known issues

 * In order to handle Versioned DataObjects janitor needs to determine which
   stages are available. There is however no way to determine this since the
   Versioned decorator does not provide a get function to access the stages
   it was initialized with. The best way to deal with this is to apply the
   Versioned patch provided with this module. it simply adds a get function.
   To do this (assuming you are a Linux user), navigate to the root directory
   of the SilverStripe installation and run:
   
   $ patch -p1 < janitor/patch/Versioned.diff
   
   and you are good to go. This patch will also add some extend() calls to
   Versioned which allows DataObjectOnUpdateDecorator to handle stages when
   DataObjects are written.

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

