<?php

/*

RWE

Copyright (C) 2004, 2005 Riku Nurminen

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with this library; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

// Name of the session variable used to store the referer from RWEUtil::locationHeader
define('RWEUTIL_SESSION_REFERER_VARIABLE', '__RWEUtil__referer');

/** 
 * Assorted utility functions.
 *
 * @note No instance of this class is created by RWE; instead, the functions
 * can be used as static methods (ie. RWEUtil::method()).
 */
class RWEUtil
{
    /** 
     * Check whether a string is a valid floating-point value (can only contain
     * digits, commas and dots).
     */
    public static function isValidFloatString($str)
    {
        if(empty($str) || preg_match("/[^\d,.]+/", $str)) {
            return false;
        }
        return true;
    }

    /** 
     * Returns a string as a floating-point value, allowing both a comma or
     * a dot as decimal separator.
     *
     * @remarks Be sure to check that the string is a valid float value with
     * RWEUtil::isValidFloatString first!
     */
    public static function getFloatValue($str)
    {
        return floatval(str_replace(',', '.', $str));
    }

    /** 
     * Redirect the browser to a page under this or other domain.
     * 
     * @param file The file to redirect to under. Applied as:
     * @code
     * header("Location: http://${httphost}/${file});
     * @endcode
     * @param referer If set, then a session variable will be set to the
     * provided string. You can then redirect back to the originating page by
     * getting the original referer with RWEUtil::getSessionReferer.
     * @param exit If false, then the script execution won't be halted when
     * this method is called. The default is true (halt exeuction).
     * @param httphost The domain to redirect to. Defaults to the value from
     * $_SERVER['HTTP_HOST']
     *
     * @sa RWE::getSessionReferer
     */
    public static function locationHeader($file = '', $referer = null, $exit = true, $httphost = null)
    {
        if(!empty($referer)) {
            RWEUtil::startSessionSafe();
            $_SESSION[RWEUTIL_SESSION_REFERER_VARIABLE] = $referer;
        }
        if(empty($httphost)) {
            $httphost = $_SERVER['HTTP_HOST'];
        }
        header("Location: http://${httphost}/${file}");
        if($exit) {
            exit(0);
        }
    }

    /** 
     * Get the referer page set by RWEUtil::locationHeader.
     * 
     * @param default_retval Value to return if no referring page is found in
     * the session. If omitted and no referer is found, an empty string ("")
     * will be returned.
     * @param unsetreferer Whether to do a session_unset() to unset the
     * referer variable after this call. Default is true.
     *
     * @sa RWEUtil::locationHeader
     */
    public static function getSessionReferer($default_retval = '', $unsetreferer = true)
    {
        RWEUtil::startSessionSafe();
        if(isset($_SESSION[RWEUTIL_SESSION_REFERER_VARIABLE])) {
            $referer = $_SESSION[RWEUTIL_SESSION_REFERER_VARIABLE];
            if($unsetreferer) {
                unset($_SESSION[RWEUTIL_SESSION_REFERER_VARIABLE]);
            }
            return $referer;
        }
        return $default_retval;
    }

    /** 
     * Searches for a file recursively from a given directory and all its
     * subdirectories.
     * 
     * @param filename The filename to search for.
     * @param basedir The basedir to start searching from.
     * 
     * @return The path to the file starting at 'basedir' if found, false if
     * not found.
     */
    public static function findFileRecursive($filename, $basedir)
    {
        if(!is_dir($basedir)) {
            return false;
        }

        $fh = null;
        if(false === ($fh = @opendir($basedir))) {
            return false;
        }
        $ds = DIRECTORY_SEPARATOR;

        // First search among files in this directory
        while(false !== ($file = readdir($fh))) {
            $dirfile = "${basedir}${ds}${file}";
            if(is_file($dirfile) && $file != '.' && $file != '..' &&
               strcmp($filename, $file) === 0)
            {
                closedir($fh);
                return $dirfile;
            }
        }

        rewinddir($fh);

        // Now search all subdirs in this directory
        while(false !== ($dir = readdir($fh))) {
            $subdir = "${basedir}${ds}${dir}";
            if(is_dir($subdir) && $dir != '.' && $dir != '..') {
                $found = RWEUtil::findFileRecursive($filename, $subdir);
                if($found !== false) {
                    closedir($fh);
                    return $found;
                }
            }
        }

        closedir($fh);

        return false;
    }

    /** 
     * Checks if the passed-in string is in valid email-address format.
     *
     * @note Does not do a real MX domain check, just checks the format of the
     * string.
     */
    public static function isValidEmailString($email)
    {
        return eregi("^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$", $email);
    }

    /** 
     * Checks if an URL "looks" valid.
     * 
     * @param url URL to check, e.g. http://www.php.net/
     */
    public static function isValidURL($url)
    {
        return eregi("^((ht|f)tp://)((([a-z0-9-]+(.[a-z0-9-]+)*(.[a-z]{2,3}))|(([0-9]{1,3}.){3}([0-9]{1,3})))((/|\?)[a-z0-9~#%&'_+=:?.-]*)*)$", $url);
    }

    /** 
     * Utility method for fetching some data from a table.
     * 
     * @param dbmgr Database manager.
     * @param table Table to fetch the data from.
     * @param selcols Columns to select (string value).
     * @param addtoqry An optional string to add to the query (e.g.
     * 'WHERE col = x').
     * 
     * @return On success, returns the rows as an associative-array (or an empty
     * array if no rows were returned). On failure returns false.
     */
    public static function getTableData(&$dbmgr, $table, $selcols, $addtoqry = null)
    {
        if(!is_object($dbmgr) || empty($table) || empty($selcols) ||
           !is_string($table) || !is_string($selcols))
        {
            return false;
        }
        $query = "SELECT ${selcols} FROM ${table}";
        // addToQuery
        if(!empty($addtoqry) && is_string($addtoqry)) {
            $query .= " ${addtoqry}";
        }
        $stmt = &$dbmgr->prepare($query);
        if(!$stmt->execute()) {
            return false;
        }
        return $stmt->fetchAllAssoc();
    }

    /** 
     * Utility method for checking if some data exists in a table.
     *
     * @return True if the value exists in the table, false otherwise.
     */
    public static function existsInTable(&$dbmgr, $table, $target)
    {
        if(!is_array($target) || count($target) < 1) {
            return false;
        }
        $tmp    = array_keys($target);
        $tgtcol = $tmp[0];
        $tgtval = $target[$tgtcol];
        $query  = "SELECT ${tgtcol} FROM ${table} WHERE ${tgtcol} = :1";
        $stmt   = $dbmgr->prepare($query);
        if(!$stmt->execute($tgtval)) {
            return false;
        }
        if($stmt->numRows() > 0) {
            return true;
        }
        return false;
    }

    /** 
     * Reverse htmlentities().
     *
     * @author Funky Ants + others @
     * <a href="http://www.php.net/htmlentities">www.php.net/htmlentities</a>
     * comments
     */
    public static function unHTMLEntities($string)
    {
        $trans_tbl = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES);
        $trans_tbl = array_flip($trans_tbl);
        $ret       = strtr($string, $trans_tbl);
        return preg_replace('/&#(\d+);/me', "chr('\\1')", $ret);
    }

    /**
     * Formats an array into a string, with various formatting options.
     *
     * @remarks If called with just one argument (an array), then is equivalent
     * to calling implode(', ', $array). Additional formatting can be done
     * by passing additional params (see below).
     *
     * @param keystoo If true (default: false), then includes the array keys in
     * the returned string. By default the keys will be separated from their
     * values with a space (e.g. '[key] [value]'). The key/value separator
     * can be changed with the 'keyvalsep' param.
     *
     * @param keyvalsep The separator to use between a key and a value when
     * 'keystoo' (see above) is true. Defaults to space (' ').
     *
     * @param elemsep The separator to use for array elements, ie. values or
     * key/value pairs if keystoo is true. Defaults to ', '.
     */
    public static function arrayToString($array, $keystoo = false, $keyvalsep = ' ', $elemsep = ', ')
    {
        if(!is_array($array)) {
            return $array;
        }
        $ret = '';
        if(!$keystoo) {
            return implode($elemsep, $array);
        } else {
            $i = 0;
            $cnt = count($array);
            foreach($array as $key => $val) {
                $c = $i + 1;
                if($c == $cnt) {
                    // No elemsep after last item
                    $ret .= $key . $keyvalsep . $val;
                } else {
                    $ret .= $key . $keyvalsep . $val . $elemsep;
                }
                $i++;
            }
        }
        return $ret;
    }

    /** 
     * Get the size of a file with the proper postfix added (KB, MB, etc).
     *
     * @param file Full path to the file.
     *
     * @note This method was called fileSize in RWE versions <= 0.2. In those
     * versions it also accepted path relative from the web root directory
     * (as set by RWE::setWebRoot) instead of full path.
     *
     * @author gnif at spacevs dot com
     */
    public static function getFileSize($file)
    {
        $size = 0;
        if(($size = @filesize($file)) === false) {
            return '';
        }
        $sizes = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');
        $ext   = $sizes[0];
        for($i = 1; (($i < count($sizes)) && ($size >= 1024)); $i++) {
            $size = $size / 1024;
            $ext  = $sizes[$i];
        }
        return round($size) . $ext;
    }

    /** 
     * Returns the provided UNIX timestamp as a full ISO 8601 date and
     * time string with timezone.
     * 
     * @param time UNIX timestamp.
     * @param utc If true, will return the provided UNIX timestamp in
     * UTC time.
     *
     * @return The provided timestamp in full ISO 8601 date and time format
     * with timezone, or an empty string ("") if the timestamp is not numeric
     * or is a negative value.
     *
     * @author jseng at pobox dot org dot sg
     */
    public static function iso8601Date($time, $utc = false) {
        if(!is_numeric($time) || $time < 0) {
            return '';
        }
        $date = '';
        if(!$utc) {
            $tzd = date('O', $time);
            $tzd = $tzd[0] . str_pad((int) ($tzd / 100), 2 , "0", STR_PAD_LEFT) .
                ':' . str_pad((int) ($tzd % 100), 2 , "0", STR_PAD_LEFT);
            $date = date('Y-m-d\TH:i:s', $time) . $tzd;
        } else {
            $utc_offset = date('Z', $time);
            $date = date('Y-m-d\TH:i:s\Z', $time - $utc_offset);
        }
        return $date;
    }

    /** 
     * Formats a string so that it's nice for usage in an url.
     *
     * @remarks Useful for e.g. formatting a news-entry title from a database
     * record into a "permanent link" link. You can then convert it back with
     * RWEUtil::formatStringFromURL to fetch that article back.
     *
     * @param string The string to format.
     * @param convtbl The conversion table. Defaults to the following (note that
     * the order of the elements in the array matters!):
     * @code
     * array('&#8217;' => '_nq_',
     *       '&#8220;' => '_sq_',
     *       '&#8221;' => '_eq_',
     *       '&amp;'   => '_et_',
     *       "'"       => '_q_',
     *       ';'       => '_dc_',
     *       ':'       => '_dd_',
     *       '+'       => '_n_',
     *       ','       => '_c_',
     *       '.'       => '_d_',
     *       '!'       => '_e_',
     *       '?'       => '_a_',
     *       ' '       => '_',
     *       );
     * @endcode
     *
     * @note This method resided in the RWE class in versions prior to 1.0.
     * The 'convtbl' parameter also didn't exist then; instead the default table
     * was stored internally in the class and to set different table you had to
     * use the RWE::setConversionTable method (and RWE::getConversionTable to
     * fetch the table).
     *
     * @sa <a href="http://www.rakkis.net/web/rwe/manual/">RWE User manual</a>
     * chapter <a href="http://www.rakkis.net/web/rwe/manual/chap3/#sect4">3.4. The RWE class, Other usages</a>.
     */
    public static function formatStringForURL($string,
                                $convtbl = array('&#8217;' => '_nq_',
                                                 '&#8220;' => '_sq_',
                                                 '&#8221;' => '_eq_',
                                                 '&amp;'   => '_et_',
                                                 "'"       => '_q_',
                                                 ';'       => '_dc_',
                                                 ':'       => '_dd_',
                                                 '+'       => '_n_',
                                                 ','       => '_c_',
                                                 '.'       => '_d_',
                                                 '!'       => '_e_',
                                                 '?'       => '_a_',
                                                 ' '       => '_',
                                                 )
                                )
    {
        $search  = array_keys($convtbl);
        $replace = $convtbl;
        return str_replace($search, $replace, $string);
    }

    /** 
     * See RWEUtil::formatStringForURL (this is its reverse).
     *
     * @note This method resided in the RWE class in versions prior to 1.0.
     * The 'convtbl' parameter also didn't exist then; instead the default table
     * was stored internally in the class and to set different table you had to
     * use the RWE::setConversionTable method (and RWE::getConversionTable to
     * fetch the table).
     *
     * @sa <a href="http://www.rakkis.net/web/rwe/manual/">RWE User manual</a>
     * chapter
     * <a href="http://www.rakkis.net/web/rwe/manual/chap3/#sect4">3.4. The RWE class, Other usages</a>. 
     */
    public static function formatStringFromURL($string,
                                 $convtbl = array('&#8217;' => '_nq_',
                                                  '&#8220;' => '_sq_',
                                                  '&#8221;' => '_eq_',
                                                  '&amp;'   => '_et_',
                                                  "'"       => '_q_',
                                                  ';'       => '_dc_',
                                                  ':'       => '_dd_',
                                                  '+'       => '_n_',
                                                  ','       => '_c_',
                                                  '.'       => '_d_',
                                                  '!'       => '_e_',
                                                  '?'       => '_a_',
                                                  ' '       => '_',
                                                  )
                                 )
    {
        $search  = $convtbl;
        $replace = array_keys($convtbl);
        return str_replace($search, $replace, $string);
    }

    /** 
     * Start a PHP session, or do nothing if session has already been started.
     * 
     * @remarks Use this method instead of session_start() to start a session
     * safely so that an E_NOTICE warning will not be generated if a session
     * has already been started.
     */
    public static function startSessionSafe()
    {
        if(!session_id()) {
            session_start();
        }
    }

    /** 
     * Gets the time the script has been executing so far.
     *
     * @param starttime The script starting time as a floating-point UNIX
     * timestamp. You can obtain this value with:
     * @code
     * $mtime     = explode(' ', microtime());
     * $starttime = (float)$mtime[1] + (float)$mtime[0];
     * @endcode
     * 
     * @return Seconds the scripts has been executing so far, as a
     * floating-point value, with the decimal digits representing fractions
     * of a second.
     */
    public static function getScriptExecTimeNow($starttime)
    {
        $s     = (float)$starttime;
        $mtime = explode(' ', microtime());
        $tend  = (float)$mtime[1] + (float)$mtime[0];
        return ((float)$tend - (float)$s);
    }

    /** 
     * Converts HTML tags to "bracket" tags.
     *
     * @remarks In the passed-in 'string', converts all HTML tags from the
     * keys of the 'transtbl' array to bracket-tags in the corresponding values
     * of the array.
     * @par
     * Note that you can use the special string %VAL% in the keys/values of
     * transtbl to pass over e.g. html attribute values. Example:
     * @code
     * array('<p class="%VAL%">' => '[p class="%VAL%"]');
     * @endcode
     */
    public static function htmlToBracketTags($string, $transtbl)
    {
        foreach($transtbl as $bracket_tag => $html_tag) {
            // Check whether to do a normal dummy tag replace, or whether we have
            // %VAL% attribs in which case we need to do a regexp replace
            if(strstr($html_tag, '%VAL%')) {
                // HTML tag with %VAL% attribs, build regex to convert back
                //------------------------------------------------------------
                // Escape '/'-characters
                $htregex = str_replace('/', '\/', $html_tag);
                // Replace %VAL% with a regex to match the attribute value
                // Match until ending quote
                $htregex = str_replace('%VAL%', '([^"]+)', $htregex); // " .. don't cry doxygen
                // Replace spaces with \s
                $htregex = str_replace(' ', '\s', $htregex);
                // In bracket tag, replace all %VAL% values with backreferences
                $valcnt  = substr_count($bracket_tag, '%VAL%') + 1;
                $btregex = str_replace('"%VAL%"', '&quot;\\BACKREFNUM&quot;', $bracket_tag);
                for($i = 1; $i < $valcnt; $i++) {
                    $btregex = preg_replace("/BACKREFNUM/", $i, $btregex, 1);
                } 
                $pattern = "/${htregex}/";
                $string  = preg_replace($pattern, $btregex, $string);
            } else {
                // Normal tag, dummy replace
                //------------------------------------------------------------
                $string = str_replace($html_tag, $bracket_tag, $string);
            }
        }
        return $string;
    }

    /** 
     * Converts "bracket" tags to HTML tags.
     *
     * @remarks In the passed-in 'string', converts all bracket-tags from the
     * keys of the 'transtbl' array to HTML tags in the corresponding values
     * of the array.
     * @par
     * Note that you can use the special string %VAL% in the keys/values of
     * transtbl to pass over e.g. html attribute values. Example:
     * @code
     * array('[p class="%VAL%"]' => '<p class="%VAL%">');
     * @endcode
     */
    // NB: Keep this the last method in this file; for some reason doxygen
    // chokes up on the "if(strstr($bracket_tag, "%VAL%")) {" line and messes
    // up the documentation after that.
    public static function bracketToHtmlTags($string, $transtbl, $nicequotes = false)
    {
        foreach($transtbl as $bracket_tag => $html_tag) {
            // Check whether to do a normal dummy tag replace, or whether we have
            // %VAL% attribs in which case we need to do a regexp replace
            if(strstr($bracket_tag, '%VAL%')) {
                // Bracket tag with %VAL% attribs, build regex to replace
                //------------------------------------------------------------
                // Escape [ and ] in bracket tag
                $btregex = str_replace('[', '\[', $bracket_tag);
                $btregex = str_replace(']', '\]', $btregex);
                // Double quotes got htmlentities()'d or nicequote'd earlier in
                // $string so format bracket tag regex to match accordingly
                $quotstart = '';
                $quotend   = '';
                // See which double-quote entity to use
                if($nicequotes) {
                    $quotstart = '&#8220;';
                    $quotend   = '&#8221;';
                } else {
                    $quotstart = '&quot;';
                    $quotend   = '&quot;';
                }
                $btregex = str_replace('"%VAL%"', $quotstart . '%VAL%' . $quotend, $btregex);
                // Replace %VAL% with a regex to match the attribute value
                // Match until ending quote
                $btregex = str_replace('%VAL%', '([^"]+)', $btregex); // " .. don't cry doxygen
                // Replace spaces with \s
                $btregex = str_replace(' ', '\s', $btregex);
                // In HTML tag, replace all %VAL% values with backreferences
                $valcnt  = substr_count($html_tag, '%VAL%') + 1;
                $htregex = str_replace('%VAL%', '\\BACKREFNUM', $html_tag);
                for($i = 1; $i < $valcnt; $i++) {
                    $htregex = preg_replace("/BACKREFNUM/", $i, $htregex, 1);
                } 
                $pattern = "/${btregex}/";
                $string  = preg_replace($pattern, $htregex, $string);
            } else {
                // Normal tag, dummy replace
                //------------------------------------------------------------
                $string = str_replace($bracket_tag, $html_tag, $string);
            }
        }
        return $string;
    }

}

?>
