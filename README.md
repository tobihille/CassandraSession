# Requirements
* Cassandra-Library for PHP: https://github.com/duoshuo/php-cassandra
* Apache Cassandra (http://cassandra.apache.org/)
* Magento 1 (obviously)
* nodetool must be in current path, otherwise the cron which removes tombstones will not work

# How To Use
* Install Cassandra (follow the Instructions on http://cassandra.apache.org/download or google it for your OS. Works on WSL too)
* Copy Cassandra-Library to lib/DuoShuo/ApacheCassandra
* clone this repository and use e.g. modman to link it
* Insert at least <cassandra_session><host>127.0.0.1</host></cassandra_session> to your app/etc/local.xml

# Warning
Cm_RedisSession is automatically deactivated in app/etc/modules/Tobihille_CassandraSession.xml to avoid rewrite conflicts

# License
* MIT
