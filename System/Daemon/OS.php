<?php
/* vim: set noai expandtab tabstop=4 softtabstop=4 shiftwidth=4: */
/**
 * System_Daemon turns PHP-CLI scripts into daemons.
 * 
 * PHP version 5
 *
 * @category  System
 * @package   System_Daemon
 * @author    Kevin van Zonneveld <kevin@vanzonneveld.net>
 * @copyright 2008 Kevin van Zonneveld (http://kevin.vanzonneveld.net)
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD Licence
 * @version   SVN: Release: $Id$
 * @link      http://trac.plutonia.nl/projects/system_daemon
 */

/**
 * Operating System focussed functionality.
 *
 * @category  System
 * @package   System_Daemon
 * @author    Kevin van Zonneveld <kevin@vanzonneveld.net>
 * @copyright 2008 Kevin van Zonneveld (http://kevin.vanzonneveld.net)
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD Licence
 * @version   SVN: Release: $Id$
 * @link      http://trac.plutonia.nl/projects/system_daemon
 * 
 */
class System_Daemon_OS
{

    /**
     * Holds errors
     *
     * @var array
     */
    public $errors = array();
    
    /**
     * Operating systems and versions are based on the existence and
     * the information found in these files.
     * The order is important because Ubuntu has also has a Debian file
     * for compatibility purposes. So in this case, scan for most specific
     * first.
     *
     * @var array
     */    
    public $osVersionFiles = array(
        "Mandrake"=>"/etc/mandrake-release",
        "SuSE"=>"/etc/SuSE-release",
        "RedHat"=>"/etc/redhat-release",
        "Ubuntu"=>"/etc/lsb-release",
        "Debian"=>"/etc/debian_version"
    );
    
    /**
     * Array that holds the properties of the parent
     * daemon. Can be inheritted, or overridden by using
     * the $properties parameter of the constructor
     *
     * @var array
     */
    protected $daemonProperties = array();
    
    /**
     * Cache that holds values of some functions 
     * for performance gain. Easier then doing 
     * if (!isset($this->XXX)) { $this->XXX = $this->XXX(); }
     * every time, in my opinion. 
     *
     * @var array
     */
    private $_intFunctionCache = array();
    
    
    
    /**
     * Making the class non-abstract with a private constructor does a better
     * job of preventing instantiation than just marking the class as abstract.
     * 
     */
    public function __construct() 
    {
        
    }    
    
    
        
    /**
     * Sets daemon specific properties
     *  
     * @param array $properties Contains the daemon properties
     * 
     * @return array
     */       
    public function setProperties($properties = false) 
    {
        if (!is_array($properties) || !count($properties)) {
            $this->errors[] = "No properties to ".
                "forge init.d script";
            return false; 
        }
                
        // Tests
        $required_props = array("appName", "appDescription", "appDir", 
            "authorName", "authorEmail");
        
        // Check if all required properties are available
        $success = true;
        foreach ($required_props as $required_prop) {
            if (!isset($properties[$required_prop])) {
                $this->errors[] = "Cannot forge an ".
                    "init.d script without a valid ".
                    "daemon property: ".$required_prop;
                $success        = false;
                continue;
            }
            
            // Addslashes
            $properties[$required_prop] = 
                addslashes($properties[$required_prop]);
        }
        
        // Override
        $this->daemonProperties = $properties;
        return $success;
        
    } // end setProperties
        
    /**
     * Returns an array(main, distro, version) of the OS it's executed on
     *
     * @return array
     */
    public function determine()
    {
        // This will not change during 1 run, so just cache the result
        if (!isset($this->_intFunctionCache[__FUNCTION__])) {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $main   = "Windows";
                $distro = PHP_OS;
            } else if (stristr(PHP_OS, "Darwin")) {
                $main   = "BSD";
                $distro = "Mac OSX";
            } else if (stristr(PHP_OS, "Linux")) {
                $main = php_uname('s');
                foreach ($this->osVersionFiles as $distro=>$osv_file) {
                    if (file_exists($osv_file)) {
                        $version = trim(file_get_contents($osv_file));
                        break;
                    }
                }
            } else {
                return false;
            }

            $this->_intFunctionCache[__FUNCTION__] = compact("main", "distro", 
                "version");
        }

        return $this->_intFunctionCache[__FUNCTION__];
    }//end determine()  
    
    /**
     * Writes an: 'init.d' script on the filesystem
     *
     * @param bolean $overwrite May the existing init.d file be overwritten?
     * 
     * @return mixed boolean on failure, string on success
     * @see initDLocation()
     * @see initDForge()
     */
    public function writeAutoStart($overwrite = false)
    {
        // Up to date filesystem information
        clearstatcache();
        
        // Collect init.d path
        $initd_location = $this->initDLocation();
        if (!$initd_location) {
            // Explaining errors should have been generated by 
            // System_Daemon_OS::initDLocation() 
            // already
            return false;
        }
        
        // Collect init.d body
        $initd_body = $this->initDForge();
        if (!$initd_body) {
            // Explaining errors should have been generated by osInitDForge() 
            // already
            return false;
        }
        
        // As many safety checks as possible
        if (!$overwrite && file_exists(($initd_location))) {
            $this->errors[] = "init.d script already exists";
            return false;
        } 
        if (!is_dir($dir = dirname($initd_location))) {
            $this->errors[] =  "init.d directory: '".
                $dir."' does not ".
                "exist. Can this be a correct path?";
            return false;
        }
        if (!is_writable($dir = dirname($initd_location))) {
            $this->errors[] =  "init.d directory: '".
                $dir."' cannot be ".
                "written to. Check the permissions";
            return false;
        }
        
        if (!file_put_contents($initd_location, $initd_body)) {
            $this->errors[] =  "init.d file: '".
                $initd_location."' cannot be ".
                "written to. Check the permissions";
            return false;
        }
        
        if (!chmod($initd_location, 0777)) {
            $this->errors[] =  "init.d file: '".
                $initd_location."' cannot be ".
                "chmodded. Check the permissions";
            return false;
        } 
        
        return $initd_location;
    }//end writeAutoStart() 
    
    /**
     * Returns an: 'init.d' script path as a string. For now only Debian & Ubuntu
     * Results are cached because they will not change during one run.
     *
     * @return mixed boolean on failure, string on success
     * @see $_intFunctionCache
     * @see determine()
     */
    public function initDLocation()
    {
        // This will not change during 1 run, so just cache the result
        if (!isset($this->_intFunctionCache[__FUNCTION__])) {
            $initd_location = false;

            // Daemon properties
            $properties = $this->daemonProperties;
                        
            // Collect OS information
            list($main, $distro, $version) = array_values($this->determine());
            
            // Where to collect the skeleton (template) for our init.d script
            switch (strtolower($distro)){
            case "debian":
            case "ubuntu":
                // Here it is for debian systems
                $initd_location = "/etc/init.d/".$properties["appName"];
                break;
            default:
                // Not supported yet
                $this->errors[] = "skeleton retrieval for OS: ".
                    $distro." currently not supported ";
                return false;
            }
            
            $this->_intFunctionCache[__FUNCTION__] = $initd_location;
        }
        
        return $this->_intFunctionCache[__FUNCTION__];
    }//end initDLocation()
    
    /**
     * Returns an: 'init.d' script as a string. for now only Debian & Ubuntu
     * 
     * @throws System_Daemon_Exception
     * @return mixed boolean on failure, string on success
     */
    public function initDForge()
    {
        // Initialize & check variables
        $skeleton_filepath = false;
        
        // Daemon properties
        $properties = $this->daemonProperties;
                
        // Check path
        $daemon_filepath = $properties["appDir"]."/".$properties["appExecutable"];
        if (!file_exists($daemon_filepath)) {
            $this->errors[] = "unable to forge startup script for non existing ".
                "daemon_filepath: ".$daemon_filepath.", try setting a valid ".
                "appDir or appExecutable";
            return false;
        }
        
        // Daemon file needs to be executable 
        if (!is_executable($daemon_filepath)) {
            $this->errors[] = "unable to forge startup script. ".
                "daemon_filepath: ".$daemon_filepath.", needs to be executable ".
                "first";
            return false;
        }
        
        // Collect OS information
        list($main, $distro, $version) = array_values($this->determine());

        // Where to collect the skeleton (template) for our init.d script
        switch (strtolower($distro)){
        case "debian":
        case "ubuntu":
            // here it is for debian based systems
            $skeleton_filepath = "/etc/init.d/skeleton";
            break;
        default:
            // not supported yet
            $this->errors[] = "skeleton retrieval for OS: ".$distro.
                " currently not supported ";
            return false;
            break;
        }

        // Open skeleton
        if (!$skeleton_filepath || !file_exists($skeleton_filepath)) {
            $this->errors[] =  "skeleton file for OS: ".$distro." not found at: ".
                $skeleton_filepath;
            return false;
        }
        
        if ($skeleton = file_get_contents($skeleton_filepath)) {
            // Skeleton opened, set replace vars
            switch (strtolower($distro)){
            case "debian":
            case "ubuntu":                
                $replace = array(
                    "Foo Bar" => $properties["authorName"],
                    "foobar@baz.org" => $properties["authorEmail"],
                    "daemonexecutablename" => $properties["appName"],
                    "Example" => $properties["appName"],
                    "skeleton" => $properties["appName"],
                    "/usr/sbin/\$NAME" => $daemon_filepath,
                    "Description of the service"=> $properties["appDescription"],
                    " --name \$NAME" => "",
                    "--options args" => "",
                    "# Please remove the \"Author\" ".
                        "lines above and replace them" => "",
                    "# with your own name if you copy and modify this script." => ""
                );
                break;
            default:
                // Not supported yet
                $this->errors[] = "skeleton modification for OS: ".$distro.
                    " currently not supported ";
                return false;
                break;
            }

            // Replace skeleton placeholders with actual daemon information
            $skeleton = str_replace(array_keys($replace), 
                array_values($replace), 
                $skeleton);

            // Return the forged init.d script as a string
            return $skeleton;
        }
    }//end initDForge()
}//end class
?>