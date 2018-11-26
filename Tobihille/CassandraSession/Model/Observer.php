<?php

class Tobihille_CassandraSession_Model_Observer {

    protected function getCassandraConnection($config) : Cassandra\Connection
    {
        // Database config
        $host =                 ((string) $config->descend('host') ?: '127.0.0.1');
        $port =                    ((int) $config->descend('port') ?: '9042');
        $user =                 ((string) $config->descend('username') ?: '');
        $pass =                 ((string) $config->descend('password') ?: '');
        $connectTimeout =          ((int) $config->descend('connect_timeout') ?: '5');
        $timeout =                 ((int) $config->descend('timeout') ?: '30');
        $persistentConnection = ((string) $config->descend('persistentConnection') ?: '');
        $dbName =               ((string) $config->descend('db') ?: 'sessions');

        $configArray = [
            // advanced way, using Connection\Stream, persistent connection
            'host'		=> $host,
            'port'		=> $port,
            'class'		=> 'Cassandra\Connection\Stream', //use stream instead of socket, default socket. Stream may not work in some environment
            'connectTimeout' => $connectTimeout, // connection timeout, default 5,  stream transport only
            'timeout'	=> $timeout, // write/recv timeout, default 30, stream transport only
            'persistent'	=> $persistentConnection, // use persistent PHP connection, default false,  stream transport only
        ];

        if (!empty($pass) && !empty($user)) {
            $configArray['password'] = $pass;
            $configArray['username'] = $user;
        }

        /** @var \Cassandra\Connection $cassandra */
        $cassandra = new Cassandra\Connection([$configArray], $dbName);
        $cassandra->setConsistency(\Cassandra\Request\Request::CONSISTENCY_ALL);
        $cassandra->connect();

        return $cassandra;
    }

    public function clear_stray_locks() {
        $config = Mage::getConfig()->getNode('global/cassandra_session');
        if (!$config) {
            return;
        }

        $dbName = ((string) $config->descend('db') ?: 'sessions');
        $cassandra = $this->getCassandraConnection($config);

        /** @var Cassandra\Response\Result $allLocks */
        $allLocks = $cassandra->querySync("select sessionkey from $dbName.session_locks");
        $sessionkeys = $allLocks->fetchAll();
        foreach ($sessionkeys as $sessionkey) {
            $sessionkey = str_replace('\'', '\'\'', $sessionkey['sessionkey']);
            /** @var \Cassandra\Response\Result $countResult */
            $countResult = $cassandra->querySync("select count(sessionkey) from $dbName.session where sessionkey = '$sessionkey'");
            $count = $countResult->fetchOne();
            if ($count === 0) {
                $cassandra->queryAsync("delete from $dbName.session_locks where sessionkey = '$sessionkey'");
            }
        }

        $cassandra->flush();
        $cassandra->disconnect();
    }

    public function clear_tombstones() {
        $config = Mage::getConfig()->getNode('global/cassandra_session');
        if (!$config) {
            return;
        }

        $cassandra = $this->getCassandraConnection($config);
        /** @var Cassandra\Response\Result $releaseVersionResult */
        $releaseVersionResult = $cassandra->querySync("SELECT release_version FROM system.local");
        $version = $releaseVersionResult->fetchOne();

        if (version_compare($version, '3.10.0', '<')) {
            return; //no nodetool garbagecollect present
        }

        $commandOut = [];
        $returnValue = 0;
        exec('which nodetool', $commandOut, $returnValue);
        if ($returnValue === 0 && is_array($commandOut) && !empty($commandOut[0])) {
            exec('nodetool garbagecollect');
        }

        $cassandra->flush();
        $cassandra->disconnect();
    }
}