<?php

/**
 * SyncData
 *
 * @author Team phpManufaktur <team@phpmanufaktur.de>
 * @link https://addons.phpmanufaktur.de/SyncData
 * @copyright 2013 Ralf Hertsch <ralf.hertsch@phpmanufaktur.de>
 * @license MIT License (MIT) http://www.opensource.org/licenses/MIT
 */

namespace phpManufaktur\SyncData\Control;

use phpManufaktur\SyncData\Control\JSON\JSONFormat;

/**
 * Utils and help functions for SyncData
 *
 * @author ralf.hertsch@phpmanufaktur.de
 *
 */
class Utils
{
    protected $app = null;
    protected static $count_files = 0;
    protected static $count_directories = 0;
    protected static $count_tables = 0;

    /**
     * Constructor
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Reset or set the counter for processed files
     *
     * @param number $count
     */
    public static function setCountFiles($count=0)
    {
        self::$count_files = $count;
    }

    /**
     * Get the counted files
     *
     * @return number
     */
    public static function getCountFiles()
    {
        return self::$count_files;
    }

    /**
     * Increase the counted files by one
     */
    public static function increaseCountFiles()
    {
        self::$count_files++;
    }

    /**
     * Reset or set the counter for directories
     *
     * @param number $count
     */
    public static function setCountDirectories($count=0)
    {
        self::$count_directories = $count;
    }

    /**
     * Get the counted directories
     *
     * @return number
     */
    public static function getCountDirectories()
    {
        return self::$count_directories;
    }

    /**
     * Increase the counted directories by one
     */
    public static function increaseCountDirectories() {
        self::$count_directories++;
    }

    /**
     * Reset or set the counter for tables
     *
     * @param number $count
     */
    public static function setCountTables($count=0)
    {
        self::$count_tables = $count;
    }

    /**
     * Get the counted tables
     *
     * @return number
     */
    public static function getCountTables()
    {
        return self::$count_tables;
    }

    /**
     * Increase the counted tables by one
     */
    public static function increaseCountTables() {
        self::$count_tables++;
    }

    /**
     * Generates a strong password of N length containing at least one lower case letter,
     * one uppercase letter, one digit, and one special character. The remaining characters
     * in the password are chosen at random from those four sets.
     *
     * The available characters in each set are user friendly - there are no ambiguous
     * characters such as i, l, 1, o, 0, etc. This, coupled with the $add_dashes option,
     * makes it much easier for users to manually type or speak their passwords.
     *
     * Note: the $add_dashes option will increase the length of the password by
     * floor(sqrt(N)) characters.
     *
     * @link https://gist.github.com/tylerhall/521810
     *
     * @param number $length
     * @param string $add_dashes
     * @param string $available_sets
     * @return string
     */
    public static function generatePassword($length=9, $add_dashes=false, $available_sets='luds')
    {
        $sets = array();
        if (strpos($available_sets, 'l') !== false)
            $sets[] = 'abcdefghjkmnpqrstuvwxyz';
        if (strpos($available_sets, 'u') !== false)
            $sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        if (strpos($available_sets, 'd') !== false)
            $sets[] = '23456789';
        if (strpos($available_sets, 's') !== false)
            $sets[] = '!@#$%&*?';

        $all = '';
        $password = '';

        foreach($sets as $set) {
            $password .= $set[array_rand(str_split($set))];
            $all .= $set;
        }

        $all = str_split($all);
        for ($i = 0; $i < $length - count($sets); $i++) {
            $password .= $all[array_rand($all)];
        }

        $password = str_shuffle($password);

        if (!$add_dashes) {
            return $password;
        }

        $dash_len = floor(sqrt($length));
        $dash_str = '';
        while (strlen($password) > $dash_len) {
            $dash_str .= substr($password, 0, $dash_len) . '-';
              $password = substr($password, $dash_len);
           }
           $dash_str .= $password;
           return $dash_str;
    }

    /**
     *
     * @access protected
     * @return
     **/
    public function listFiles($folder='inbox',$prefix='')
    {
        $path = $this->sanitizePath(sprintf('%s/%s/', SYNCDATA_PATH, $folder));
        $this->app['monolog']->addDebug('listFiles ['.$path.']');
        return $this->getFiles($path,$prefix);
    }   // end function listFiles()

    /**
     *
     * @access public
     * @return
     **/
    public function getFiles($basedir,$prefix='',$with_md5=false)
    {
        $zip_files   = array();

        $this->app['monolog']->addInfo(sprintf(
            'getFiles from basedir %s [prefix: %s]', $basedir, $prefix
        ), array('method' => __METHOD__, 'line' => __LINE__));

        if(!is_dir($basedir)) {
            $this->app['monolog']->addError(
                'no such directory!',
                array('method' => __METHOD__, 'line' => __LINE__)
            );
            return array();
        }

        $directory_handle = dir($basedir);
        while (false !== ($file = $directory_handle->read())) {
            // get all files into an array
            if(($file == '.') || ($file == '..')) continue;
            $path = $this->app['utils']->sanitizePath("$basedir/$file");
            $this->app['monolog']->addInfo(sprintf(
                'checking file [%s]', $path
            ), array('method' => __METHOD__, 'line' => __LINE__));
            if(is_dir($path)) {
                $this->app['monolog']->addInfo('>>> it\'s a directory');
                continue;
            }
            if(!file_exists($path)) {
                $this->app['monolog']->addInfo('>>> does not exist (???)');
                continue;
            }
            if(substr_compare(pathinfo($path,PATHINFO_FILENAME), $prefix, 0, strlen($prefix))) {
                $this->app['monolog']->addInfo(sprintf(
                    '>>> [%s] does not match prefix [%s] (compare result: %s)',
                    pathinfo($path,PATHINFO_FILENAME),
                    $prefix,
                    substr_compare(pathinfo($path,PATHINFO_FILENAME), $prefix, 0, strlen($prefix))
                ));
                continue;
            }
            if(pathinfo($path,PATHINFO_EXTENSION) == 'zip') {
                // find md5 file
                $md5_path = $this->app['utils']->sanitizePath("$basedir/".pathinfo($path,PATHINFO_FILENAME).'.md5');
                if(!file_exists($md5_path)) {
                    $this->app['monolog']->addInfo(sprintf(
                        'no checksum found (%s)',$md5_path
                    ), array('method' => __METHOD__, 'line' => __LINE__));
                    continue; // invalid zip file
                }
                // check file checksum
                if (false === ($md5_origin = file_get_contents($md5_path))) {
                    $this->app['monolog']->addError('Failed to read the checksum, skipping', array('method' => __METHOD__, 'line' => __LINE__));
                    continue;
                } else {
                    $this->app['monolog']->addInfo(
                        sprintf('checksum [%s]',$md5_origin)
                    );
                }
                $checksum_file = md5_file($path);
                $this->app['monolog']->addInfo(
                    sprintf('checksum2 [%s]',$checksum_file)
                );
                if ($checksum_file !== $md5_origin) {
                    $this->app['monolog']->addError('Wrong checksum, skipping', array('method' => __METHOD__, 'line' => __LINE__));
                    continue;
                }
                // finally, add the file to the list
                $zip_files[] = pathinfo($path,PATHINFO_BASENAME);
            }
            elseif(pathinfo($path,PATHINFO_EXTENSION) == 'md5' && $with_md5) {
                $zip_files[] = pathinfo($path,PATHINFO_BASENAME);
            }
        }
        $this->app['monolog']->addInfo(sprintf(
            'found [%s] files', count($zip_files)
        ), array('method' => __METHOD__, 'line' => __LINE__));
        // sort the array ascending
        sort($zip_files);
        return $zip_files;
    }   // end function getFiles()

    /**
     * Initialisierung curl
     **/
    public function init_client($is_remote=false, $add_headers=null)
    {
        $headers = array(
            'User-Agent: php-curl',
        );

        if(is_array($add_headers) && count($add_headers))
            $headers = array_merge($headers,$add_headers);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
// NUR ZUM TESTEN
        if($is_remote)
        {
#            curl_setopt($ch, CURLOPT_PROXY, 'proxy.materna.de');
#            curl_setopt($ch, CURLOPT_PROXYPORT, '8080');
        }
// NUR ZUM TESTEN
        return $ch;
    }
    
    /**
     *
     * @access protected
     * @return
     **/
    public function pushFile($file,$destination)
    {
        // check the file path
        if($this->checkPath($file))
        {
            $this->app['monolog']->addInfo(sprintf(
                'pushing file [%s]', $file
            ), array('method' => __METHOD__, 'line' => __LINE__));
            try {
                $cfile = new \CURLFile($file);
                $url   = sprintf(
// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
                    '%s/syncdata/upload_confirmations?key=%s',
// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
                    $this->app['config']['sync'][$destination]['url'],
                    $this->app['config']['sync'][$destination]['key']
                );
                $ch = $this->init_client(true);
                curl_setopt($ch, CURLOPT_URL, $url);
                $postData = array('file' => $cfile, 'checksum' => md5_file($file));
        	    curl_setopt($ch, CURLOPT_POST,1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                $response = curl_exec($ch);
                $this->app['monolog']->addInfo('----- PUSHFILE CURL RESPONSE ----- '.print_r($response,1));
                $result = json_decode($response,true);
                return $result;
            } catch( \Exception $e ) {
                $this->app['monolog']->addError(
                    'pushFile Exception: '.$e->getMessage,
                    array('method' => __METHOD__, 'line' => __LINE__)
                );
                throw new \Exception();
            }
        } else {
            $this->app['monolog']->addError(sprintf(
                'Unable to push file [%s]: Invalid path!',
                $file
            ));
            return false;
        }
    }   // end function pushFile()

    /**
     * Remove a directory recursivly
     *
     * @link http://www.php.net/manual/de/function.rmdir.php#110489
     * @param string $dir
     * @return boolean
     */
    public function rrmdir($dir) {
        try {
            $files = array();
            if (false === ($scan_dir = @scandir($dir))) {
                throw new \Exception(sprintf("Can't scan the directory %s", $dir));
            }
            $files = array_diff($scan_dir, array('.','..'));
        } catch (\Exception $e) {
            $this->app['monolog']->addInfo($e->getMessage(), error_get_last());
        }
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->rrmdir("$dir/$file") : @unlink("$dir/$file");
        }
        return @rmdir($dir);
    }

    /**
     * Sanitize a path, revert backslashes, resolves '..' etc.
     *
     * @link https://github.com/webbird/LEPTON_2_BlackCat/blob/master/upload/framework/CAT/Helper/Directory.php
     * @param string $path
     * @return string
     */
    public static function sanitizePath($path)
    {
        // remove / at end of string; this will make sanitizePath fail otherwise!
        $path = preg_replace( '~/{1,}$~', '', $path );
        // make all slashes forward
        $path = str_replace( '\\', '/', $path );
        // bla/./bloo ==> bla/bloo
        $path = preg_replace('~/\./~', '/', $path);
        // loop through all the parts, popping whenever there's a .., pushing otherwise.
        $parts = array();
        foreach (explode('/', preg_replace('~/+~', '/', $path)) as $part) {
            if ($part === ".." || $part == '') {
                array_pop($parts);
            }
            elseif ($part != "") {
                $parts[] = $part;
            }
        }
        $new_path = implode("/", $parts);
        // windows
        if (!preg_match('/^[a-z]\:/i', $new_path)) {
            $new_path = '/' . $new_path;
        }
        return $new_path;
    }

    /**
     * create directory recursive
     *
     * @param  string  $dir_name
     * @param  string  $dir_mode
     * @return boolean
     **/
	public function createRecursive($dir_name, $dir_mode=NULL)
	{
         if ( ! $dir_mode )
         {
             $dir_mode = (int) octdec($this->defaultDirMode());
         }
	     if ( $dir_name != '' && !is_dir($dir_name) )
	     {
	         $umask = umask(0);
	         mkdir($dir_name, $dir_mode, true);
	         umask($umask);
	         return true;
	     }
	     return false;
	 }   // end function createRecursive()

    /**
	 * If the configuration setting 'string_dir_mode' is missing, we need
	 * a default value that fits most cases.
	 *
     * @access public
     * @return string
     **/
    public function defaultDirMode() {
        return (
              (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
            ? '0777'
            : '0755'
        );
    }   // end function defaultDirMode()

    /**
     * Copy files recursive from source to destination
     *
     * @param string $source_directory
     * @param string $destination_directory
     * @param array $ignore_directories must contains full path
     * @param array $ignore_subdirectories directory name only
     * @param array $ignore_files filename only
     * @param boolean $delete_directory_before remove the directory before copying
     * @param array reference $copied_files collect all copied files with relative path
     * @throws \Exception
     * @return boolean
     */
    public function copyRecursive($source_directory, $destination_directory, $ignore_directories=array(),
        $ignore_subdirectories=array(), $ignore_files=array(), $delete_directory_before=false, &$copied_files=array())
    {
        if (is_dir($source_directory))
            $directory_handle = dir($source_directory);
        else
            return false;
        if (!is_object($directory_handle)) return false;

        while (false !== ($file = $directory_handle->read())) {
            if (($file == '.') || ($file == '..')) continue;
            $source = self::sanitizePath($source_directory.DIRECTORY_SEPARATOR.$file);
            $target = self::sanitizePath($destination_directory.DIRECTORY_SEPARATOR.$file);
            if (is_dir($source)) {
                // check directories
                $skip = false;
                foreach ($ignore_directories as $directory) {
                    if ($source == $directory) {
                        $this->app['monolog']->addInfo(sprintf('Skipped directory %s', $source),
                            array('method' => __METHOD__, 'line' => __LINE__));
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;
                // check subdirectory
                if (in_array(substr(dirname($source), strrpos(dirname($source), DIRECTORY_SEPARATOR)+1), $ignore_subdirectories)) {
                    $this->app['monolog']->addInfo(sprintf('Skipped subdirectory %s', $source),
                        array('method' => __METHOD__, 'line' => __LINE__));
                    continue;
                }
                // delete before copying?
                if ($delete_directory_before && file_exists($target)) {
                    $this->rrmdir($target);
                }
                // create directory in the target
                if (!file_exists($target) && (true !== @mkdir($target, 0755, true ))) {
                    // get the reason why mkdir() fails
                    $error = error_get_last();
                    throw new \Exception("Can't create directory $target, error message: {$error['message']}");
                }
                // set the datetime
                if (false === @touch($target, filemtime($source))) {
                    $this->app['monolog']->addInfo("Can't set the modification date/time for $target",
                        array('method' => __METHOD__, 'line' => __LINE__));
                }
                self::increaseCountDirectories();
                // recursive call
                $this->copyRecursive($source, $target, $ignore_directories, $ignore_subdirectories, $ignore_files, $delete_directory_before, $copied_files);
            }
            else {
                // check files
                if (in_array(basename($source), $ignore_files)) {
                    $this->app['monolog']->addInfo(sprintf('Skipped file %s', $source),
                        array('method' => __METHOD__, 'line' => __LINE__));
                    continue;
                }
                // check subdirectory
                if (in_array(substr(dirname($source), strrpos(dirname($source), DIRECTORY_SEPARATOR)+1), $ignore_subdirectories)) {
                    $this->app['monolog']->addInfo(sprintf('Skipped subdirectory %s', $source),
                        array('method' => __METHOD__, 'line' => __LINE__));
                    continue;
                }
                // copy file to the target
                if (true !== @copy($source, $target)) {
                    throw new \Exception("Can't copy file $source");
                }
                // set the datetime
                if (false === @touch($target, filemtime($source))) {
                    $this->app['monolog']->addInfo("Can't set the modification date/time for $file",
                        array('method' => __METHOD__, 'line' => __LINE__));
                }
                // add file to the copied_files array
                $copied_files[] = substr($source, strlen(CMS_PATH));
                // increase the counter
                self::increaseCountFiles();
            }
        }
        $directory_handle->close();
        return true;
    }

    /**
     * Create a .htaccess and .htpasswd protection with a random user and password
     *
     * @param string $path
     * @throws \Exception
     */
    public function createDirectoryProtection($path)
    {
        if (!file_exists($path)) {
            if (true !== @mkdir($path, 0755, true)) {
            throw new \Exception("Can not create the directory $path!");
        }
        $this->app['monolog']->addInfo('Create the directory $path',
            array('method' => __METHOD__, 'line' => __LINE__));
        }
        // create protection for the desired directory
        $data = sprintf("# .htaccess generated by SyncData\nAuthUserFile %s/.htpasswd\n" .
            "AuthName \"SyncData protection\"\nAuthType Basic\n<Limit GET>\n" .
            "require valid-user\n</Limit>", $path);
        if (!@file_put_contents(sprintf('%s/.htaccess', self::sanitizePath($path)), $data)) {
            throw new \Exception("Can't write .htaccess for config directory protection!");
        }
        // generate a password; this requires PHP >= 5.5.0
        $pw   = password_hash(self::generatePassword(16), PASSWORD_DEFAULT);
        $data = sprintf("# .htpasswd generated by SyncData\nsync_user:%s", $pw);
        if (!@file_put_contents(sprintf('%s/.htpasswd', self::sanitizePath($path)), $data)) {
            throw new \Exception("Can't write .htpasswd for config directory protection!");
        }
        $this->app['monolog']->addInfo("Created .htaccess protection for the directory $path",
            array('method' => __METHOD__, 'line' => __LINE__));
    }

    /**
     * Sanitize variables and prepare them for saving in a MySQL record
     *
     * @param mixed $item
     * @return mixed
     */
    public static function sanitizeVariable ($item)
    {
        if (!is_array($item)) {
            // undoing 'magic_quotes_gpc = On' directive
            if (get_magic_quotes_gpc())
                $item = stripcslashes($item);
            $item = self::sanitizeText($item);
        }
        return $item;
    }

    /**
     * Sanitize a text variable and prepare it for saving in a MySQL record
     *
     * @param string $text
     * @return string
     */
    public static function sanitizeText ($text)
    {
        $search = array("<",">","\"","'","\\","\x00","\n","\r","'",'"',"\x1a");
        $replace = array("&lt;","&gt;","&quot;","&#039;","\\\\","\\0","\\n","\\r","\'",'\"',"\\Z");
        return str_replace($search, $replace, $text);
    }

    /**
     * Unsanitize a text variable and prepare it for output
     *
     * @param string $text
     * @return string
     */
    public static function unsanitizeText($text)
    {
        $text = stripcslashes($text);
        $text = str_replace(
            array("&lt;","&gt;","&quot;","&#039;"),
            array("<",">","\"","'"), $text);
        return $text;
    }

    /**
     * Scan the given $locale_path for language files and add them to the global
     * translator resource
     *
     * @param string $locale_path
     * @throws \Exception
     */
    function addLanguageFiles($locale_path)
    {
        // scan the /Locale directory and add all available languages
        try {
            if (false === ($lang_files = scandir($locale_path)))
                throw new \Exception(sprintf("Can't read the /Locale directory %s!", $locale_path));
            $ignore = array('.', '..', 'index.php', 'README.md');
            foreach ($lang_files as $lang_file) {
                if (!is_file($locale_path.'/'.$lang_file)) continue;
                if (in_array($lang_file, $ignore) || (pathinfo($locale_path.'/'.$lang_file, PATHINFO_EXTENSION) != 'php')) continue;
                $lang_name = pathinfo($locale_path.'/'.$lang_file, PATHINFO_FILENAME);
                // get the array from the desired file
                $lang_array = include_once $locale_path.'/'.$lang_file;
                // add the locale resource file
                $this->app['translator'] = $this->app->share($this->app->extend('translator', function ($translator) use ($lang_array, $lang_name) {
                    $translator->addResource('array', $lang_array, $lang_name);
                    return $translator;
                }));
                $this->app['monolog']->addInfo('Added language file: '.substr($locale_path, strlen(SYNCDATA_PATH)).'/'.$lang_file);
            }
        }
        catch (\Exception $e) {
            throw new \Exception(sprintf('Error scanning the /Locale directory %s.', $locale_path));
        }
    } // addLanguageFiles()

    /**
     * Generate a globally unique identifier (GUID)
     * Uses COM extension under Windows otherwise
     * create a random GUID in the same style
     *
     * @return string $guid
     */
    public static function createGUID ()
    {
        if (function_exists('com_create_guid')) {
            $guid = com_create_guid();
            $guid = strtolower($guid);
            if (strpos($guid, '{') == 0) {
                $guid = substr($guid, 1);
            }
            if (strpos($guid, '}') == strlen($guid) - 1) {
                $guid = substr($guid, 0, strlen($guid) - 2);
            }
            return $guid;
        } else {
            return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        }
    } // createGUID()

    /**
     * Read the specified configuration file in JSON format and return array
     *
     * @param string $file path to JSON file
     * @throws \Exception
     * @return array configuration items
     */
    public function readConfiguration($file)
    {
        if (file_exists($file)) {
            if (null === ($config = json_decode(file_get_contents($file), true))) {
                $code = json_last_error();
                // get JSON error message from last error code
                switch ($code) :
                case JSON_ERROR_NONE:
                    $error = 'No errors';
                break;
                case JSON_ERROR_DEPTH:
                    $error = 'Maximum stack depth exceeded';
                    break;
                case JSON_ERROR_STATE_MISMATCH:
                    $error = 'Underflow or the modes mismatch';
                    break;
                case JSON_ERROR_CTRL_CHAR:
                    $error = 'Unexpected control character found';
                    break;
                case JSON_ERROR_SYNTAX:
                    $error = 'Syntax error, malformed JSON';
                    break;
                case JSON_ERROR_UTF8:
                    $error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                    break;
                default:
                    $error = 'Unknown error';
                    break;
                    endswitch;

                    // throw Exception
                    throw new \Exception(sprintf('Error decoding JSON file %s, returned error code: %d - %s',
                        substr($file, strlen(SYNCDATA_PATH)), $code, $error));
            }
        } else {
            throw new \Exception(sprintf('Missing the configuration file: %s!', $file));
        }
        // return the configuration array
        return $config;
    } // readConfiguration()

    /**
     * Alias for readConfiguration()
     *
     * @see readConfiguration()
     * @param string $file path to JSON file
     * @return Ambigous <multitype:, mixed>
     */
    public function readJSON($file)
    {
        return $this->readConfiguration($file);
    }

    /**
     * Return a valid path to the desired template, depending on the namespace,
     * the preconfigured Framework template names and/or the preferred template
     *
     * @param string $template_namespace the Twig namespace to use
     * @param string $template_file the file to load, you can use leading directories
     * @param string $preferred_template optional specifiy a preferred template
     * @param boolean $return_path return the path instead of the Twig namespace
     * @throws \Exception
     * @return string
     */
    public function getTemplateFile($template_namespace, $template_file, $preferred_template='', $return_path=false)
    {
        $TEMPLATE_NAMESPACES = array(
            'phpManufaktur' => MANUFAKTUR_PATH,
        );

        if ($template_namespace[0] != '@') {
            throw new \Exception('Namespace expected in variable $template_namespace but path found!');
        }
        // no trailing slash!
        if (strrpos($template_namespace, '/') == strlen($template_namespace) - 1)
            $template_namespace = substr($template_namespace, 0, strlen($template_namespace) - 1);
        // separate the namespace
        if (false === strpos($template_namespace, '/')) {
            // only namespace - no subdirectory!
            $namespace = substr($template_namespace, 1);
            $directory = '';
        } else {
            $namespace = substr($template_namespace, 1, strpos($template_namespace, '/') - 1);
            $directory = substr($template_namespace, strpos($template_namespace, '/'));
        }

        // no leading slash for the template file
        if ($template_file[0] == '/')
            $template_file = substr($template_file, 1);
        // explode the template names
        $template_names = explode(',', SYNCDATA_TEMPLATES);
        if (!empty($preferred_template)) {
            array_unshift($template_names, $preferred_template);
        }

        // walk through the template names
        foreach ($template_names as $name) {
            $file = $TEMPLATE_NAMESPACES[$namespace] . $directory . '/' . $name . '/' . $template_file;
            if (file_exists($file)) {
                if ($return_path) {
                    // return the PATH
                    return $file;
                }
                else {
                    // success - build the namespace path for Twig
                    return $template_namespace . '/' . $name . '/' . $template_file;
                }
            }
        }
        // Uuups - no template found!
        throw new \Exception(sprintf('Template file %s not found within the namespace %s!', $template_file, $template_namespace));
    }

    /**
     * Like json_encode but format the JSON in a human friendly way
     *
     * @param array $chunk the array to save as JSON
     * @param string $already_json set true if $chunk is already JSON and should be formatted
     * @return string
     */
    public function JSONFormat($chunk, $already_json = false)
    {
        $JSONFormat = new JSONFormat();
        return $JSONFormat->format($chunk, $already_json);
    }

    /**
     * check for application/json header
     **/
    public function checkJSON()
    {
        $headers = getallheaders();
        if(isset($headers['Accept']) && preg_match('~application/json~i',$headers['Accept']))
            return true;
        else
            return false;
    }   // end function checkJSON()

    /**
     * validate path against ['config']['security']['push_paths']
     *
     * @access public
     * @param  string  $path
     * @return boolean
     **/
    public function checkPath($path)
    {
        $paths = $this->app['config']['security']['push_paths'];
        foreach(array_values($paths) as $subdir)
        {
            $fullpath = $this->sanitizePath(
                sprintf('%s/%s', SYNCDATA_PATH, $subdir)
            );
            if(!substr_compare($path,$fullpath,0,strlen($fullpath),false)) {
                return true;
            }
        }
        return false;
    }   // end function checkPath()

    /**
     *
     * @access public
     * @return
     **/
    public function parseResponse($result)
    {
        $is_error = false;
        $message  = NULL;
        $dom      = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);

        $dom->loadHTML($result);

        if($dom->getElementById('content')->hasChildNodes())
        {
            $nodes    = $dom->getElementsByTagName("div");
            foreach ($nodes as $element)
            {
                $class = $element->getAttribute("class");
                if($class=='error')
                {
                    $is_error = true;
                }
                if($class=='message')
                {
                    $message = ( $is_error ? '[ERROR] ' : '' ) . $element->textContent;
                }
            }
        }

        return array($is_error,$message);
    }   // end function parseResponse()
    

    /**
     *
     * @access public
     * @return
     **/
    public function handleJSONError($throw_exception=false)
    {
        $msg = 'JSON Error Code ['.json_last_error().']: ';
        switch(json_last_error()) {
            case JSON_ERROR_NONE:
                $msg .= 'Keine Fehler';
            break;
            case JSON_ERROR_DEPTH:
                $msg .= 'Maximale Stacktiefe überschritten';
            break;
            case JSON_ERROR_STATE_MISMATCH:
                $msg .= 'Unterlauf oder Nichtübereinstimmung der Modi';
            break;
            case JSON_ERROR_CTRL_CHAR:
                $msg .= 'Unerwartetes Steuerzeichen gefunden';
            break;
            case JSON_ERROR_SYNTAX:
                $msg .= 'Syntaxfehler, ungültiges JSON';
            break;
            case JSON_ERROR_UTF8:
                $msg .= 'Missgestaltete UTF-8 Zeichen, möglicherweise fehlerhaft kodiert';
            break;
            default:
                $msg .= 'Unbekannter Fehler';
            break;
        }
        if($throw_exception) {
            throw new \Exception($msg);
        }
        return $msg;
    }   // end function handleJSONError()
    

    /**
     * Parse a PHP file for defined constants.
     * If $constant = null return a array with all constants or false if none exists.
     * If $constant is a named return the defined value or false, if the constant does
     * not exists.
     *
     * @param string $php_file
     * @param string $constant
     * @throws \Exception
     * @return boolean|array
     * @link http://stackoverflow.com/a/645914/2243419
     */
    public function parseFileForConstants($php_file, $constant=null)
    {
        function is_constant($token) {
            return $token == T_CONSTANT_ENCAPSED_STRING || $token == T_STRING ||
            $token == T_LNUMBER || $token == T_DNUMBER;
        }

        function strip($value) {
            return preg_replace('!^([\'"])(.*)\1$!', '$2', $value);
        }

        $defines = array();
        $state = 0;
        $key = '';
        $value = '';

        if (false === ($file = file_get_contents($php_file))) {
            throw new \Exception("Can not read the content of the file $php_file!");
        }

        $tokens = token_get_all($file);
        $token = reset($tokens);

        while ($token) {
            if (is_array($token)) {
                if ($token[0] == T_WHITESPACE || $token[0] == T_COMMENT || $token[0] == T_DOC_COMMENT) {
                    // do nothing
                }
                elseif ($token[0] == T_STRING && strtolower($token[1]) == 'define') {
                    $state = 1;
                }
                elseif ($state == 2 && is_constant($token[0])) {
                    $key = $token[1];
                    $state = 3;
                }
                elseif ($state == 4 && is_constant($token[0])) {
                    $value = $token[1];
                    $state = 5;
                }
            } else {
                $symbol = trim($token);
                if ($symbol == '(' && $state == 1) {
                    $state = 2;
                }
                elseif ($symbol == ',' && $state == 3) {
                    $state = 4;
                }
                elseif ($symbol == ')' && $state == 5) {
                    $defines[strip($key)] = strip($value);
                    $state = 0;
                }
            }
            $token = next($tokens);
        }

        if (is_null($constant)) {
            return !empty($defines) ? $defines : false;
        }
        else {
            foreach ($defines as $key => $value) {
                if (strtolower($key) == strtolower($constant)) {
                    return $value;
                }
            }
            return false;
        }
    }
}
