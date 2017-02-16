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

class Autosync {

    protected $app = null;

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
     * starts a new autosync job
     *
     * @access public
     * @return string
     **/
    public function sync()
    {
        $this->app['monolog']->addInfo(
            'Starting new AUTOSYNC Job', array('method' => __METHOD__, 'line' => __LINE__)
        );
        if(!$this->startJob())
        {
            return $this->app['translator']->trans(
                'Unable to start a new synchronization job!'
            );
        }
        return 'Synchronization in progress, please wait...';
    }   // end function sync()

    /**
     * get the current state / progress of a job
     * prints JSON result and exit()s
     *
     * @access public
     * @return void
     **/
    public function poll()
    {
        if(
            (
                   !isset($_GET['jobid'])
                || !$this->checkJob($_GET['jobid'])
            )
        ) {
            $this->app['monolog']->addError(sprintf(
                'no such job: [%s]', $_GET['jobid']
            ));
            echo json_encode(array(
                'success'  => false,
                'message'  => '- invalid request -',
                'finished' => true,
            ),1);
            exit();
        }

        $errmsg   = null;
        $finished = false;
        $success  = true;

        // read job file
        $file = $this->app['utils']->sanitizePath(
            sprintf('%s/temp/jobs/autosync_job_%s', SYNCDATA_PATH, $_GET['jobid'])
        );
        $progress = str_replace("\n","<br />\n",file($file));
        if(substr_count(implode('',$progress),'Job finished')) {
            $finished = true;
        }

        // check if there's an error file
        $errfile = $this->app['utils']->sanitizePath(
            sprintf('%s/temp/jobs/autosync_job_%s.error', SYNCDATA_PATH, $_GET['jobid'])
        );
        if(file_exists($errfile)) {
            $errmsg = str_replace("\n","<br />\n",file($errfile));
            $success = false;
        }

        $result = array(
            'success'  => $success,
            'message'  => $progress,
            'finished' => $finished,
            'errors'   => $errmsg,
        );

        $result = json_encode($result,1);

        header('Content-type: application/json');
        echo $result;

        if($finished) $this->finishJob();

        exit();
    }   // end function poll()

    /**
     *
     * @access public
     * @return
     **/
    public function checkConnection($role='server')
    {
        try {
            $this->app['monolog']->addInfo(sprintf(
                '>>> checkConnection(%s) url [%s]',
                $role, $this->app['config']['sync'][$role]['url']
            ), array('method' => __METHOD__, 'line' => __LINE__));

            $remote_ch = $this->app['utils']->init_client(true); // maybe with proxy
            curl_setopt($remote_ch, CURLOPT_URL, $this->app['config']['sync'][$role]['url']);
            $result = curl_exec($remote_ch);
            if(curl_getinfo($remote_ch,CURLINFO_HTTP_CODE) != 200)
            {
                $this->app['monolog']->addError(sprintf(
                    'connection failed to url [%s]! Code: %s',
                    $this->app['config']['sync'][$role]['url'],
                    curl_getinfo($remote_ch,CURLINFO_HTTP_CODE)
                ), array('method' => __METHOD__, 'line' => __LINE__));
                self::$error_msg = sprintf(
                    $this->app['translator']->trans(
                        'Unable to connect to server! (Code: %s)'
                    ),
                    curl_getinfo($remote_ch,CURLINFO_HTTP_CODE)
                );
                $this->app['monolog']->addError(
                    self::$error_msg, array('method' => __METHOD__, 'line' => __LINE__)
                );
                $this->logProgress(array('message'=>self::$error_msg,'success'=>false));
                return false;
            }
        } catch (\Exception $e) {
            self::$error_msg = $e->getMessage();
            $this->app['monolog']->addError($e->getMessage());
            return false;
        }
        return true;
    }   // end function checkConnection()

    /**
     * checks if a job exists
     *
     * @access public
     * @return boolean
     **/
    public function checkJob($jobid)
    {
        $file = $this->app['utils']->sanitizePath(
            sprintf('%s/temp/jobs/autosync_job_%s', SYNCDATA_PATH, $jobid)
        );
        if(!file_exists($file)) {
            return false;
        }
        define('SYNCDATA_JOBID', $jobid);
        return true;
    }   // end function checkJob()

    /**
     * create / update job
     *
     * @access public
     * @param  string $message
     * @return boolean
     **/
    public function logProgress($data)
    {
        // get file path
        $file = $this->app['utils']->sanitizePath(
            sprintf('%s/temp/jobs/autosync_job_%s', SYNCDATA_PATH, SYNCDATA_JOBID)
        );

        // obfuscate paths
        $data['message'] = str_ireplace(
            array(
                SYNCDATA_PATH,
                str_replace('/','\\',SYNCDATA_PATH),
            ),
            array(
                '/abs/path/to',
                '/abs/path/to',
            ),
            $data['message']
        );

        // translate and replace params (if any)
        $data['message'] = $this->app['translator']->trans($data['message']);
        if(isset($data['param'])) $data['message'] = sprintf($data['message'],$data['param']);

        // mark errors
        if(substr_count($data['message'],'ERROR ') || ( isset($data['success']) && !$data['success']))
        {
            $data['message'] = '<span class="errline">'.$data['message'].'</span>';
        } else {
            // add date to message
            $data['message'] = sprintf('[%10s] %s', date('c'), $data['message']);
        }

        $mode = 'a';
        if(!file_exists($file)) $mode = 'w';

        // error
        if(isset($data['success']) && $data['success'] === false)
        {
            $fh = fopen($file.'.error',$mode);
        } else {
            $fh = fopen($file,$mode); // create new / overwrite
        }

        if(!$fh || !is_resource($fh))
        {
            $this->app['monolog']->addError('Schreiben in Jobdatei fehlgeschlagen!');
            return false;
        }
        fwrite($fh,$data['message']."\n");
        fclose($fh);

        return true;
    }

    /**
     *
     * @access public
     * @return
     **/
    public function receiveFile($subdir='inbox')
    {
        $success = false;
        $message = '';
        try {
            $this->app['monolog']->addInfo(
                'Receiving file', array('method' => __METHOD__, 'line' => __LINE__)
            );
            if(!isset($_FILES) || !isset($_FILES['file']) || !count($_FILES['file'])) {
                $this->app['monolog']->addError('Missing file!', array('method' => __METHOD__, 'line' => __LINE__));
                $message = 'No file';
            } else {
                $dest_path = $this->app['utils']->sanitizePath(
                    sprintf('%s/%s/', SYNCDATA_PATH, $subdir)
                );
                $this->app['monolog']->addInfo(sprintf(
                    'File upload path: %s', $dest_path
                ));
                // only one cross... uh... file
                $result    = $_FILES['file']['error'];
                if($result == \UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['file']['tmp_name'];
                    $this->app['monolog']->addInfo(sprintf(
                        'File upload succeeded, temp file name: %s', $tmp_name
                    ));
                    // basename() kann Directory-Traversal-Angriffe verhindern;
                    $name  = basename($_FILES['file']['name']);
                    $md5   = md5_file($tmp_name);
                    $check = $_POST['checksum'];
                    if($md5 != $check) {
                        $this->app['monolog']->addError('Checksum error!');
                        $message = 'Checksum error';
                    } else {
                        if(!file_exists("$dest_path/$name")) {
                            move_uploaded_file($tmp_name, "$dest_path/$name");
                            $fh = fopen(sprintf('%s/%s/%s.md5', SYNCDATA_PATH, $subdir, pathinfo($name,PATHINFO_FILENAME)),'w');
                            fwrite($fh,$check);
                            fclose($fh);
                            $message = 'Succeeded';
                            $success = true;
                        } else {
                            $this->app['monolog']->addError(sprintf(
                                'File already exists: [%s]', $name
                            ));
                            $message = 'File already exists';
                        }
                    }
                } else {
                    $this->app['monolog']->addError(sprintf(
                        'Upload error: %s', $result
                    ));
                    $message = 'Upload error';
                }
            }
        } catch (\Exception $e) {
            $this->app['monolog']->addError(sprintf(
                'Upload error: %s', $e->getMessage()
            ));
            $message = 'Exception: '.$e->getMessage();
        }
        header('Content-type: application/json');
        echo json_encode(array('success'=>$success,'message'=>$message),true);
        exit();
    }   // end function receiveFile()

    /**
     *
     * @access public
     * @param  string  $destination - server or client
     * @return
     **/
    public function pushOutbox($destination)
    {
        $this->app['monolog']->addInfo(
            sprintf('>>> pushOutbox to %s',$destination)
        );

        try{
            // get a list of available files
            $files = $this->app['utils']->listFiles('outbox');
            if(isset($files) && count($files))   // if there are any files...
            {
                $this->app['monolog']->addInfo(
                    sprintf('>>> pushOutbox(%s): %d files found',$destination,count($files))
                );
                $this->logProgress(array(
                    'message' => '%d files in the outbox',
                    'param'   => count($files),
                ));
                foreach(array_values($files) as $file) {
                    $path = $this->app['utils']->sanitizePath(sprintf('%s/%s/%s', SYNCDATA_PATH, 'outbox', $file));
                    $this->app['autosync']->logProgress(array(
                        'message' => '>>> sending file %s',
                        'param'   => $path,
                    ));
                    $result = $this->app['utils']->pushFile($path,$destination);
                    $this->logProgress(array(
                        'message' => 'upload result: [%s]',
                        'param'   => ( $result == false ? 'unknown error' : $result['message'] ),
                        'success' => ( $result == false ? false           : $result['success'] ),
                    ));
                }
            } else {
                $this->logProgress(array(
                    'message' => 'There are no files to be uploaded',
                    'success' => true
                ));
            }
        } catch(\Exception $e) {
            $this->app['monolog']->addError(sprintf(
                'pushOutbox failed with error: %s', $e->getMessage()
            ));
        }

    }   // end function pushOutbox()

    /**
     *
     * @access protected
     * @return
     **/
    protected function cleanupJobs()
    {
        // clean up old job files (older than 7 days)
        $path  = $this->app['utils']->sanitizePath(
            sprintf('%s/temp/jobs/', SYNCDATA_PATH)
        );
        $role     = $this->app['config']['sync']['role'];
        $interval = $this->app['config']['sync'][$role]['cleanup_interval'];
        // check the cleanup interval; max. 7 days
        if(!is_numeric($interval) || $interval <= 0 || $interval >= 604800)
        {
            $interval = 604800; // 7 days, i.e. 24*60*60*7
        }
        $files = glob($path.'/autosync_job_*');
        if(count($files))
            foreach($files as $f)
                if(filemtime($f)<(time()-$interval))
                    unlink($f);
    }   // end function cleanupJobs()

    /**
     * remove job file, unset session keys
     *
     * @access protected
     * @return void
     **/
    protected function finishJob()
    {
        // remove job file
        $file = $this->app['utils']->sanitizePath(
            sprintf('%s/temp/jobs/autosync_job_%s', SYNCDATA_PATH, SYNCDATA_JOBID)
        );
        unlink($file);
        #unset($_SESSION["JOB_".SYNCDATA_JOBID."_PROGRESS"]);
        #unset($_SESSION["JOB_".SYNCDATA_JOBID."_ERROR"]);
        #unset($_SESSION["JOB_".SYNCDATA_JOBID."_FINISHED"]);
        #unset($_SESSION["JOB_".SYNCDATA_JOBID."_ERRORCOUNT"]);
    }   // end function finishJob()

    /**
     * start a new job
     *
     * @access protected
     * @return
     **/
    protected function startJob()
    {
$this->app['monolog']->addInfo('<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<');
$caller = debug_backtrace();
$this->app['monolog']->addInfo(print_r($caller[1],1));
        // clean up old jobs
        $this->cleanupJobs();
        // generate JobID
        $jobid = $this->app['utils']->generatePassword(15,false,'lud');
        define('SYNCDATA_JOBID', $jobid);
        if($this->logProgress(
            array(
                'message' => '----- '.$this->app['translator']->trans('Job started').' -----',
            )
        )) {
            return true;
        } else {
            return false;
        }
    }   // end function startJob()
}