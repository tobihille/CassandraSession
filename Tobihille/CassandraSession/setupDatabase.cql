CREATE KEYSPACE sessions WITH replication = {'class': 'SimpleStrategy', 'replication_factor' : 1};
USE sessions;
CREATE TABLE sessions.session(sessionkey text, sessioncontent blob, PRIMARY KEY (sessionkey)) WITH default_time_to_live = 86400;
# TTLs are not allowed in counter tables
CREATE TABLE sessions.session_locks(sessionkey text, locks counter, PRIMARY KEY (sessionkey));