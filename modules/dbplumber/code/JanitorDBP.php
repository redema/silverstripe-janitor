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
 * Integration with the DB plumber module.
 */
class JanitorDBP {
	
	/**
	 * Path to where the SQL exported by DB plumber will be saved.
	 * This must be set to use self::backupDatabase(). Ideally it
	 * will be placed outside the www-root, or at least in a directory
	 * which disallows access to .sql files.
	 * @var string
	 */
	private static $sql_file_path = null;
	
	/**
	 * @param string $path
	 * @see self::$sql_file_path
	 */
	public static function set_sql_file_path($path) {
		self::$sql_file_path = $path;
	}
	
	/**
	 * @return string
	 * @see self::$sql_file_path
	 */
	public static function get_sql_file_path() {
		return self::$sql_file_path;
	}
	
	/**
	 * @return boolean True if DB plumber is available.
	 */
	public static function available() {
		return class_exists('DBP');
	}
	
	/**
	 * Use DB plumber to backup the database.
	 * 
	 * @return string The path to the database dump.
	 */
	public static function backup_database() {
		if (!self::available()) {
			trigger_error("This feature requires the Database Plumber module."
				. " See README.md for more information.", E_USER_ERROR);
		}
		if (!self::get_sql_file_path()) {
			trigger_error("You must set self::\$sql_file_path to use this feature.",
				E_USER_ERROR);
		}
		
		$SQLDialects = array(
			'MySQLDatabase' => 'MySQL',
			'SQLite3Database' => 'SQLite',
			'MSSQLDatabase' => 'MSSQL',
			'PostgreSQLDatabase' => 'Postgres'
		);
		$sql = singleton('DBP_Database_Controller')->backup(
		    DataObjectOnDeleteDecorator::get_db_tables(false),
		    @$SQLDialects[get_class(DB::getConn())]);
		
		file_put_contents(self::get_sql_file_path(), $sql);
		return self::get_sql_file_path();
	}
	
	/**
	 * Enforce JanitorDBP as a monostate class.
	 */
	private function __construct() {}
	
}

