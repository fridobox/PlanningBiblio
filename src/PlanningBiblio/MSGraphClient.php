<?php

namespace App\PlanningBiblio;

use App\Model\Agent;
use App\PlanningBiblio\OAuth;
use App\PlanningBiblio\Logger;
use App\PlanningBiblio\MSCalendarUtils;
use Unirest\Request;

class MSGraphClient
{

    private CONST BASE_URL = 'https://graph.microsoft.com/v1.0';
    private CONST CAL_NAME = 'PlanningBiblio-Absences-';
    // Start year for full scan
    private CONST START_YEAR = '2000';

    private $calendarUtils;
    private $dbprefix;
    private $entityManager;
    private $full;
    private $graphUsers;
    private $incomingEvents;
    private $localEvents;
    private $logger;
    private $login_suffix;
    private $oauth;
    private $reason_name;

    public function __construct($entityManager, $tenantid, $clientid, $clientsecret, $full, $stdout)
    {
        $tokenURL = "https://login.microsoftonline.com/$tenantid/oauth2/v2.0/token";
        $authURL = "https://login.microsoftonline.com/$tenantid/oauth2/v2.0/authorize";
        $options = [
             'scope' => 'https://graph.microsoft.com/.default'
        ];
        $this->logger = new Logger($entityManager, $stdout);
        $this->oauth = new OAuth($this->logger, $clientid, $clientsecret, $tokenURL, $authURL, $options);
        $this->msCalendarUtils = new MSCalendarUtils();
        $this->entityManager = $entityManager;
        $this->dbprefix = $_ENV['DATABASE_PREFIX'];
        $this->reason_name = $_ENV['MS_GRAPH_REASON_NAME'] ?? 'Outlook';
        $this->login_suffix = $_ENV['MS_GRAPH_LOGIN_SUFFIX'] ?? null;
        $this->full = $full;
    }

    public function retrieveEvents() {
        $this->log("Start absences import from MS Graph Calendars");
        $this->log("full scan: $this->full");
        $this->getIncomingEvents();
        $this->getLocalEvents();
        $this->deleteEvents();
        $this->insertOrUpdateEvents();
        $this->log("End absences import from MS Graph Calendars");
    }

    private function getDateRange() {
        $range = array();
        $today = date("Y-m-d");
        $range['from'] = date("Y-m-d", strtotime($today . '- 15 days'));
        $range['to'] = date("Y-m-d", strtotime($today . ' + 365 days'));
        return $range;
    }

    private function getIncomingEvents() {
        $this->incomingEvents = array();
        $this->graphUsers = array();
        $users = $this->entityManager->getRepository(Agent::class)->findBy(['supprime' => 0]);
        foreach ($users as $user) {
            if ($this->isGraphUser($user)) {
                array_push($this->graphUsers, $user->id());
                $currentYear = date("Y");
                if ($this->full) {
                    $yearCount = 0;
                    while (self::START_YEAR + $yearCount <= $currentYear) {
                        $from = (self::START_YEAR + $yearCount) . "-01-01";
                        $to = (self::START_YEAR + $yearCount) . "-12-31";
                        $this->log("Getting events from $from to $to for user ". $user->login());
                        $response = $this->getCalendarView($user, $from, $to);
                        if ($response->code == 200) {
                            $this->addToIncomingEvents($user, $response);
                        } else {
                            $this->log("Unable to get events, http status: " . $response->code);
                        }
                        $yearCount++;
                    }
                } else {
                    $range = $this->getDateRange();
                    $from = $range['from'];
                    $to = $range['to'];
                    $this->log("Getting events from $from to $to for user ". $user->login());
                    $response = $this->getCalendarView($user, $from, $to);
                    if ($response->code == 200) {
                        $this->addToIncomingEvents($user, $response);
                    } else {
                        $this->log("Unable to get events, http status: " . $response->code);
                    }
                }
            }
        }
    }

    private function addToIncomingEvents($user, $response, $nextLink = null) {
        if ($nextLink) {
            $response = $this->sendGet($nextLink, true);
            if ($response->code != 200) {
                $this->log("Unable to get events, http status: " . $response->code);
                return;
            }
        }
        foreach ($response->body->value as $event) {
            if (!$event->responseStatus || $event->responseStatus->response == "accepted" || $event->responseStatus->response == "organizer" ) {
                $this->incomingEvents[$user->id() . $event->iCalUId]['plb_id'] = $user->id();
                $this->incomingEvents[$user->id() . $event->iCalUId]['plb_login'] = $user->login();
                $this->incomingEvents[$user->id() . $event->iCalUId]['last_modified'] = $event->lastModifiedDateTime;
                $this->incomingEvents[$user->id() . $event->iCalUId]['event'] = $event;
            }
        }

        if (property_exists($response->body, '@odata.nextLink')) {
            $this->log("Paginate " . $response->body->{'@odata.nextLink'});
            $this->addToIncomingEvents($user, $response, $response->body->{'@odata.nextLink'});
        }
    }

    private function getLocalEvents() {
        $usersSQLIds = join(',', $this->graphUsers);

        if ($this->full) {
            $from = self::START_YEAR . "-01-01";
            $to = date("Y") . "-12-31";
        } else {
            $range = $this->getDateRange();
            $from = $range['from'];
            $to = $range['to'];
        }
        $query = "SELECT * FROM " . $this->dbprefix . "absences WHERE motif='" . $this->reason_name . "' AND perso_id IN($usersSQLIds) AND debut >= '" . $from . "' AND debut <= '" . $to . "'";
        $statement = $this->entityManager->getConnection()->prepare($query);
        $statement->execute();
        $results = $statement->fetchAll();
        $this->localEvents = array();
        foreach ($results as $localEvent) {
            $this->localEvents[$localEvent['perso_id'] . $localEvent['ical_key']] = $localEvent;
        }
    }

    private function getCalendarView($user, $from, $to) {
        $login = $user->login();
        $response = $this->sendGet("/users/$login" . $this->login_suffix . '/calendar/calendarView?startDateTime=' . $from . 'T00:00:00.0000000&endDateTime=' . $to . 'T00:00:00.0000000&$top=200');
        if ($response->code == 200) {
            return $response;
        } else {
            $this->log("Response: $response->code");
            $this->log($response->raw_body);
        }
        return false;
    }

    private function isGraphUser($user) {
        $login = $user->login();
        $response = $this->sendGet("/users/$login" . $this->login_suffix . '/calendar');
        if ($response->code == 200) {
            return true;
        }
        return false;
    }

    private function deleteEvents() {
        // The SQL calls in this function should be replaced by doctrine calls when available
        $query = "DELETE FROM " . $this->dbprefix . "absences WHERE ical_key=:ical_key AND perso_id=:perso_id";
        $statement = $this->entityManager->getConnection()->prepare($query);
        foreach ($this->localEvents as $ical_key => $localEvent) {
            if (!array_key_exists($ical_key, $this->incomingEvents)) {
                $this->log("deleting user " . $localEvent['perso_id'] . " event " . $localEvent['ical_key']);
                $statement->bindParam(':ical_key', $localEvent['ical_key']);
                $statement->bindParam(':perso_id', $localEvent['perso_id']);
                $statement->execute();
            }
        }
    }

    private function insertOrUpdateEvents() {
        // The SQL calls in this function should be replaced by doctrine calls when available
        foreach ($this->incomingEvents as $eventArray) {
            $incomingEvent = $eventArray['event'];
            $rrule = null;

            if (!$incomingEvent->iCalUId) {
                $this->log("Cannot process event: ical_key is null");
                continue;
            }

            if (!$incomingEvent->subject) {
                $incomingEvent->subject = "[Empty]";
            }

            if (array_key_exists($eventArray['plb_id'] . $incomingEvent->iCalUId, $this->localEvents)) {
                // Event modification
                $localEvent = $this->localEvents[$eventArray['plb_id'] . $incomingEvent->iCalUId];
                if ($incomingEvent->lastModifiedDateTime != $localEvent['last_modified']) {
                    $this->log("updating user " . $eventArray['plb_id'] . " event '" . $incomingEvent->subject . "' " . $incomingEvent->iCalUId);
                    if ($incomingEvent->type == "occurrence") {
                        $response = $this->sendGet("/users/" . $eventArray['plb_login'] . $this->login_suffix . '/calendar/events/' . $incomingEvent->seriesMasterId);
                        $rrule = $this->msCalendarUtils->recurrenceToRRule($response->body->recurrence);
                    }
                    $query = "UPDATE " . $this->dbprefix . "absences SET debut=:debut, fin=:fin, motif=:motif, commentaires=:commentaires, last_modified=:last_modified, rrule=:rrule WHERE ical_key=:ical_key LIMIT 1";
                    $statement = $this->entityManager->getConnection()->prepare($query);
                    $statement->execute(array(
                        'debut'             => $this->formatDate($incomingEvent->start),
                        'fin'               => $this->formatDate($incomingEvent->end),
                        'motif'             => $this->reason_name,
                        'commentaires'      => $incomingEvent->subject,
                        'last_modified'     => $incomingEvent->lastModifiedDateTime,
                        'ical_key'          => $incomingEvent->iCalUId,
                        'rrule'             => $rrule
                    ));
                }
            } else {
                // Event insertion
                $this->log("inserting user " . $eventArray['plb_id'] . " event '" . $incomingEvent->subject . "' " . $incomingEvent->iCalUId);
                if ($incomingEvent->type == "occurrence") {
                    $response = $this->sendGet("/users/" . $eventArray['plb_login'] . $this->login_suffix . '/calendar/events/' . $incomingEvent->seriesMasterId);
                    $rrule = $this->msCalendarUtils->recurrenceToRRule($response->body->recurrence);
                }
                $query = "INSERT INTO " . $this->dbprefix . "absences ";
                $query .= "( perso_id,  debut,  fin,  motif, motif_autre, commentaires, valide, etat, demande, cal_name,  ical_key, last_modified, rrule) VALUES ";
                $query .= "(:perso_id, :debut, :fin, :motif, '',         :commentaires, 9999,   '',   NOW(),  :cal_name, :ical_key, :last_modified, :rrule)";
                $statement = $this->entityManager->getConnection()->prepare($query);
                $statement->execute(array(
                    'perso_id'      => $eventArray['plb_id'],
                    'debut'         => $this->formatDate($incomingEvent->start),
                    'fin'           => $this->formatDate($incomingEvent->end),
                    'motif'         => $this->reason_name,
                    'commentaires'  => $incomingEvent->subject,
                    'cal_name'      => self::CAL_NAME . $eventArray['plb_id'] . '-' . md5($incomingEvent->iCalUId),
                    'ical_key'      => $incomingEvent->iCalUId,
                    'last_modified' => $incomingEvent->lastModifiedDateTime,
                    'rrule'         => $rrule
                ));
            }
        }
    }

    private function sendGet($request, $absolute = false) {
        $token = $this->oauth->getToken();
        $headers['Authorization'] = "Bearer $token";
        $response = \Unirest\Request::get($absolute ? $request : self::BASE_URL . $request, $headers);
        return $response;
    }

    private function log($message) {
        $this->logger->log($message, "MSGraphClient");
    }

    private function formatDate($graphdate, $format = "Y-m-d H:i:s") {
        $time = strtotime($graphdate->dateTime . $graphdate->timeZone);
        return date($format, $time);
    }
}