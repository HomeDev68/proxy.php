<?php

/*
 * Place here any hosts for which we are NOT to be a proxy -
 * e.g. the host on which the J2EE APIs we'll be blocking are running
 * */
$SETTING_BLOCKED_HOSTS = array(
    'exampledomain.com', 'blocked.com' # change to restrict list to only domains you wish to block clients from calling via this proxy
);

// All domains are allowed
