<?php

/*
 * Place here any hosts for which we are to be a proxy -
 * e.g. the host on which the J2EE APIs we'll be proxying are running
 * */
$SETTING_ALLOWED_HOSTS = array(
    'localhost','127.0.0.1', 'httpbin.org' # change to restrict list to only domains you wish to allow clients to call via this proxy
);

/*
 * Place here any hosts for which we are NOT to be a proxy -
 * e.g. the host on which the J2EE APIs we'll be blocking are running
 * */
$SETTING_BLOCKED_HOSTS = array(
    'example.com', 'blocked.com' # change to restrict list to only domains you wish to block clients from calling via this proxy
);
