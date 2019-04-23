<?php
require_once __DIR__ . '/vendor/autoload.php';

$calendarIdArray = [
    'all' => //すべて
        [
            'room_name' =>  'hogehoge@resource.calendar.google.com',
        ],
];

if (! isset($calendarIdArray[$_REQUEST['name']])) exit('parameter error');
$calendarIdArray = $calendarIdArray[$_REQUEST['name']];

define('APPLICATION_NAME', 'Google Calendar API PHP Quickstart');
define('CREDENTIALS_PATH', __DIR__ . '/calendar-credintials.json');
define('CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json');

define('MAIL_PATTERN', "/(.*?)@example.com$/");

define('SCOPES', implode(' ', array(
        Google_Service_Calendar::CALENDAR_READONLY)
));

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getClient() {
    $client = new Google_Client();
    $client->setApplicationName(APPLICATION_NAME);
    $client->setScopes(SCOPES);
    $client->setAuthConfig(CLIENT_SECRET_PATH);
    $client->setAccessType('offline');

    // Load previously authorized credentials from a file.
    $credentialsPath = expandHomeDirectory(CREDENTIALS_PATH);

    if (file_exists($credentialsPath)) {
        $accessToken = json_decode(file_get_contents($credentialsPath), true);
    } else {
        // Request authorization from the user.
        $authUrl = $client->createAuthUrl();
        printf("Open the following link in your browser:\n%s\n", $authUrl);
        print 'Enter verification code: ';
        $authCode = trim(fgets(STDIN));

        // Exchange authorization code for an access token.
        $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

        // Store the credentials to disk.
        if(!file_exists(dirname($credentialsPath))) {
            mkdir(dirname($credentialsPath), 0700, true);
        }
        file_put_contents($credentialsPath, json_encode($accessToken));
        printf("Credentials saved to %s\n", $credentialsPath);
    }
    $client->setAccessToken($accessToken);

    // Refresh the token if it's expired.
    if ($client->isAccessTokenExpired()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
    }
    return $client;
}

/**
 * Expands the home directory alias '~' to the full path.
 * @param string $path the path to expand.
 * @return string the expanded path.
 */
function expandHomeDirectory($path) {
    $homeDirectory = getenv('HOME');
    if (empty($homeDirectory)) {
        $homeDirectory = getenv('HOMEDRIVE') . getenv('HOMEPATH');
    }
    return str_replace('~', realpath($homeDirectory), $path);
}

/**
 * 来訪かどうか
 * @param $subject
 * @return bool|false|int
 */
function isOutside($subject) {
    if (preg_match('/(社外|来訪|来客|面接|オファー)/', $subject)) return true;
    return (preg_match('/^(?!.*仕様).*(?=(様|さん|さま)).*$/', $subject));
}

// Get the API client and construct the service object.
$client = getClient();
$service = new Google_Service_Calendar($client);


$optParams = array(
    'maxResults' => 3,
    'orderBy' => 'startTime',
    'singleEvents' => TRUE,
    'timeMin' => date('c', strtotime('-29 min')),
);

$eventArray = [];
foreach ($calendarIdArray as $calendarName => $calendarId) {

    $results = $service->events->listEvents($calendarId, $optParams);

    if (count($results->getItems()) == 0) {
        print "No upcoming events found.\n";
    } else {

        foreach ($results->getItems() as $event) {

            $start = $event->start->dateTime;
            if (empty($start)) {
                $start = $event->start->date;
            }

            $end = $event->end->dateTime;
            if (empty($end)) {
                $end = $event->end->date;
            }

            if (! preg_match(MAIL_PATTERN, $event->creator->email, $match)) continue;
            $owner = [
                'name' => $match[1],
                'email' => $match[0],
                'md5' => md5($match[0]),
            ];

            $guests = [];
            foreach ($event->attendees as $guest) {
                if(!preg_match(MAIL_PATTERN, $guest->email, $match)) continue;
                if ($match[1] == 'server' || $match[1] == 'info') continue;//共用アカウント
                $guests[] = [
                    'name' => $match[1],
                    'email' => $match[0],
                    'md5' => md5($match[0]),
                ];
            }

            if (strtotime($end) < time()) {
                //var_dump('prev', $event->summary);
                $isNow = 'prev';
            }
            elseif (strtotime($start) > time()) {
                //var_dump('next', $event->summary);
                $isNow = 'next';
            }
            else {
                //var_dump('now', $event->summary);
                $isNow = 'now';
            }

            //$isNow = (strtotime($start) <= time() && strtotime($end) >= time()) ? 'now' : 'next';

            //現在開催中のイベントで、詳細欄に'display_hack'とある場合、imgタグで全画面表示する(tablet:1024x600)
            if ($isNow == 'now' && $event->description) {
                if (preg_match('/display_hack/', $event->description, $match)) {
                    preg_match('/http(.*?).(png|jpg)/', $event->description, $match);
                    $imgSrc = $match[0];
                    require_once 'template_display_hack.html';
                    exit;
                }
            }

            if (! isset($eventArray[$calendarId][$isNow])) $eventArray[$calendarId][$isNow] = [
                'roomName' => $calendarName,
                'location' => $event->location,
                'summary' => $event->summary,
                'isOutside' => isOutside($event->summary),
                'start' => date('m/d H:i', strtotime($start)),
                'end'   => date('H:i', strtotime($end)),
                'diff' => strtotime($start) - time(),
                'owner' => $owner,
                'guests' => $guests,
            ];
        }
    }
}

//すべて非公開イベントだったら
if (count($eventArray) <= 0) {
    $imgSrc = 'hidden.jpg';
    require_once 'template_display_hack.html';
    exit;
}

$now = date('Y/m/d H:i:s');
$column = (count($calendarIdArray) > 1) ? 'col-sm-6' : 'col-sm-12';
require_once 'template.html';