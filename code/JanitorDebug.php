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
 * Internal helper class for displaying debug messages. Never
 * use this class outside janitor.
 */
class JanitorDebug {
	
	/**
	 * Determines if debug messages should be printed or not.
	 * @var boolean
	 */
	private static $verbose = false;
	
	public static function set_verbose($value) {
		self::$verbose = (bool)$value;
	}
	
	public static function get_verbose() {
		return self::$verbose;
	}
	
	/**
	 * Print a debug message.
	 * 
	 * @param string $string
	 * @param string $tag
	 * @param string $style
	 */
	public static function message($string, $tag = 'p', $style = '') {
		if (self::$verbose) {
			$caller = Debug::caller();
			$file = basename($caller['file']);
			$string = "{$file}[{$caller['line']}]: {$string}";
			echo Director::is_cli()? "{$string}\n": "<{$tag} style=\"{$style}\">{$string}</{$tag}>\n";
		}
	}
	
	/**
	 * Enforce JanitorDebug as a monostate class.
	 */
	private function __construct() {}
}

