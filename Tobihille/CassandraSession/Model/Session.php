<?php

require_once Mage::getBaseDir('lib').DS.'DuoShuo'.DS.'ApacheCassandra'.DS.'php-cassandra.php';
//note: this implementation is inspired by cm_redissession and cm_mongosession from Colin Mollenhour (https://github.com/colinmollenhour)

class Tobihille_CassandraSession_Model_Session extends Mage_Core_Model_Mysql4_Session
{
    protected const SESSION_PREFIX       = 'sess_';
    protected const LOG_FILE             = 'cassandra_session.log';
    protected const DEFAULT_BREAK_AFTER  = 30;       /* Try to break the lock after this many seconds */
    protected const FAIL_AFTER           = 15;       /* Try to break lock for at most this many seconds */

    /** @var bool $_useThis */
    protected $_useThis;

    /** @var Cassandra\Connection $_cassandra */
    protected $_cassandra;

    /** @var Mage_Core_Model_Config_Element $_config */
    protected $_config;

    /** @var string $_dbName */
    protected $_dbName;

    /** @var bool $_sessionWritten */
    protected $_sessionWritten; // avoid infinite loops

    /** @var int $failedLockAttempts */
    static public $failedLockAttempts = 0; // for debug or informational purposes

    /** @var int $_breakAfter */
    protected $_breakAfter;

    /** @var int $_sessionLifetime */
    protected $_sessionLifetime = 86400;

    /** @var array $_configArray */
    protected $_configArray = [];

    public function __construct()
    {
        $this->_sessionLifetime = $this->getLifeTime();

        $this->_config = $config = Mage::getConfig()->getNode('global/cassandra_session');
        if (!$config) {
            $this->_useThis = FALSE;
            Mage::log('Cassandra configuration does not exist, falling back to MySQL handler.', Zend_Log::EMERG);
            parent::__construct();
            return;
        }

        // Database config
        $host =                 ((string) $config->descend('host') ?: '127.0.0.1');
        $port =                    ((int) $config->descend('port') ?: '9042');
        $user =                 ((string) $config->descend('username') ?: '');
        $pass =                 ((string) $config->descend('password') ?: '');
        $connectTimeout =          ((int) $config->descend('connect_timeout') ?: '5');
        $timeout =                 ((int) $config->descend('timeout') ?: '30');
        $persistentConnection = ((string) $config->descend('persistentConnection') ?: '');
        $this->_dbName =        ((string) $config->descend('db') ?: 'sessions');
        $this->_breakAfter =    ((string) $config->descend('breakafter') ?: self::DEFAULT_BREAK_AFTER);

        $this->_configArray = [
            // advanced way, using Connection\Stream, persistent connection
            'host'		=> $host,
            'port'		=> $port,
            'class'		=> 'Cassandra\Connection\Stream', //use stream instead of socket, default socket. Stream may not work in some environment
            'connectTimeout' => $connectTimeout, // connection timeout, default 5,  stream transport only
            'timeout'	=> $timeout, // write/recv timeout, default 30, stream transport only
            'persistent'	=> $persistentConnection, // use persistent PHP connection, default false,  stream transport only 
        ];
        
        if (!empty($pass) && !empty($user)) {
            $this->_configArray['password'] = $pass;
            $this->_configArray['username'] = $user;
        }
        
        // Connect and authenticate
        $this->_cassandra = new Cassandra\Connection([$this->_configArray], $this->_dbName);
        $this->_cassandra->setConsistency(\Cassandra\Request\Request::CONSISTENCY_ALL);
        
        $this->_useThis = TRUE;
    }

    /**
     * @param $msg
     * @param $level
     */
    protected function _log($msg, $level = Zend_Log::DEBUG): void
    {
        Mage::log("{$this->_getPid()}: $msg", $level, self::LOG_FILE);
    }

    /**
     * Check DB connection
     *
     * @return bool
     */
    public function hasConnection()
    {
        if (!$this->_useThis) {
            return parent::hasConnection();
        }

        try {
            $this->_cassandra->connect();
            return TRUE;
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_cassandra = NULL;
            Mage::log('Unable to connect to Cassandra; falling back to MySQL handler', Zend_Log::EMERG);

            // Fall-back to MySQL handler. If this fails, the file handler will be used.
            $this->_useThis = FALSE;
            parent::__construct();
            return parent::hasConnection();
        }
    }

    /**
     * Fetch session data
     *
     * @param string $sessId
     * @return string
     */
    public function read($sessId) : string
    {
        if (!$this->_useThis) {
            return parent::read($sessId);
        }

        $sessionId = self::SESSION_PREFIX.$sessId;
        $sessionId = str_replace('\'', '\'\'', $sessionId);

        // Get lock on session. If the count of locks is "0" everything is fine
        // If the new value is exactly BREAK_AFTER then we also have the lock and have waited long enough for the
        // previous previous process to finish.
        $tries = 0;
        while(1) {
            $locks = 0;
            try {
                /** @var \Cassandra\Response\Result $result */
                $result = $this->_cassandra->querySync(
                    "select locks from {$this->_dbName}.session_locks where sessionkey = ?",
                    [new Cassandra\Type\Varchar($sessionId)]);
                /** @var int $locks */
                $locks = $result->fetchOne();
                if ($locks === null) { //new session
                    $locks = 0;
                }
            } catch (\Cassandra\Exception $e) {
                $this->_log("checking for lock failed: {$e->getMessage()} ({$e->getCode()})");
                Mage::logException($e);
                return '';
            }

            // If we got the lock, update with our pid and reset lock and expiration
            if ($locks <= 0 || $locks == $this->_breakAfter) {
                try {
                    //set lock
                    $this->_cassandra->queryAsync(
                        "update {$this->_dbName}.session_locks set locks = locks + 1 where sessionkey = ?",
                        [new Cassandra\Type\Varchar($sessionId)]);
                    //update TTL
                    $this->_cassandra->queryAsync(
                        "update {$this->_dbName}.session set sessionkey = ? where sessionkey = ? using TTL {$this->_sessionLifetime}",
                        [new Cassandra\Type\Varchar($sessionId), new Cassandra\Type\Varchar($sessionId)]);

                    $result = $this->_cassandra->querySync(
                        "select sessioncontent from {$this->_dbName}.session where sessionkey = ?",
                        [new Cassandra\Type\Varchar($sessionId)]);

                    $content = $result->fetchOne();
                    if ($content === null) { //new session
                        $content = '';
                    }
                    return $this->_decodeData($content);
                } catch (\Cassandra\Exception $e) {
                    $this->_log("querying session data failed: {$e->getMessage()} ({$e->getCode()})");
                    Mage::logException($e);
                    return '';
                }
            }

            if(++$tries >= self::FAIL_AFTER) {
                return '';
            }
            sleep(1);
        } //while

        return '';
    }

    /**
     * Update session
     *
     * @param string $sessId
     * @param string $sessData
     * @return boolean
     */
    public function write($sessId, $sessData) : bool
    {
        if (!$this->_useThis) {
            return parent::write($sessId, $sessData);
        }

        $sessionId = self::SESSION_PREFIX.$sessId;
        $sessionId = str_replace('\'', '\'\'', $sessionId);
        $sessionData = str_replace('\'', '\'\'', $sessData);

        $sessionData = $this->_encodeData($sessionData);

        // If we lost our lock on the session we should not overwrite it.
        // It should always exist since the read callback created it.
        $this->_cassandra->queryAsync(
            "update {$this->_dbName}.session using TTL {$this->_sessionLifetime} set sessioncontent = ? where sessionkey = ?",
            [new Cassandra\Type\Blob($sessionData), new Cassandra\Type\Varchar($sessionId)]);
        $this->_cassandra->queryAsync(
            "update {$this->_dbName}.session_locks set locks = locks - 1 where sessionkey = ?",
            [new Cassandra\Type\Varchar($sessionId)]);
        return TRUE;
    }

    /**
     * Destroy session
     *
     * @param string $sessId
     * @return boolean
     */
    public function destroy($sessId) : bool
    {
        if (!$this->_useThis) {
            return parent::destroy($sessId);
        }

        $sessionId = self::SESSION_PREFIX.$sessId;
        $sessionId = str_replace('\'', '\'\'', $sessionId);

        $this->_cassandra->querySync(
            "delete from {$this->_dbName}.session where sessionkey = ?",
            [new Cassandra\Type\Varchar($sessionId)]);
        $this->_cassandra->queryAsync(
            "delete from {$this->_dbName}.session_locks where sessionkey = ?",
            [new Cassandra\Type\Varchar($sessionId)]);

        return TRUE;
    }

    /**
     * Garbage collection
     *
     * @param int $sessMaxLifeTime ignored
     * @return boolean
     */
    public function gc($sessMaxLifeTime) : bool
    {
        if (!$this->_useThis) {
            return parent::gc($sessMaxLifeTime);
        }

        //since TTL is set everywhere nothing should be necessary here
        //TODO: maybe it's a good idea to clear table session_locks from sessionkeys that are not existent in session
        return TRUE;
    }

    /**
     * @return string
     */
    public function _getPid() : string
    {
        return gethostname().'|'.getmypid();
    }

    public function _decodeData(String $data) : string
    {
        $uncompressedData = gzuncompress($data);
        if ($uncompressedData !== false) {
            return $uncompressedData;
        }
        return $data;
    }

    public function _encodeData(String $data) {
        $compressedData = gzcompress($data, 9);
        if ($compressedData !== false) {
            return $compressedData;
        }

        return $data;
    }
}
