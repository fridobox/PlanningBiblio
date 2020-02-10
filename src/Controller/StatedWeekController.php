<?php

namespace App\Controller;

use App\Controller\BaseController;
use App\Model\Agent;
use App\Model\StatedWeek;
use App\Model\StatedWeekColumn;
use App\Model\StatedWeekJob;
use App\Model\StatedWeekTimes;
use App\Model\StatedWeekJobTimes;
use App\Model\StatedWeekPause;

use App\PlanningBiblio\Helper\StatedWeekHelper;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

include_once(__DIR__ . '/../../public/include/function.php');
require_once(__DIR__ . '/../../public/absences/class.absences.php');
require_once(__DIR__ . '/../../public/conges/class.conges.php');

class StatedWeekController extends BaseController
{
    private $CSRFToken;

    /**
     * @Route("/ajax/statedweek/lock", name="statedweek.lock", methods={"GET", "POST"})
     */
    public function lockPlanning(Request $request)
    {
        $response = new Response();

        $this->CSRFToken = $request->get('CSRFToken');
        $date = $request->get('date');
        if (!$date) {
            $response->setContent('Missing date');
            $response->setStatusCode(400);
            return $response;
        }

        $date_pl = new \datePl($date);
        $dates = $date_pl->dates;
        if (!$this->config('Dimanche')) {
            unset($dates[6]);
        }

        $lock = false;
        if ($request->get('lock')) {
            $lock = true;
        }

        $plannings = array();
        foreach ($dates as $d) {
            $planning = $this->getPlanningOn($d);
            $plannings[] = $planning;
            $planning->locked($lock);
            if ($lock) {
                $planning->locker_id($_SESSION['login_id']);
                $planning->locked_on(new \DateTime('now'));
            }
            $this->entityManager->persist($planning);
            $this->entityManager->flush();
        }

        if ($lock) {
            $statedweekHelper = new StatedWeekHelper($plannings);
            $statedweekHelper->CSRFToken = $this->CSRFToken;
            $statedweekHelper->saveToWeeklyPlannings();
        }

        $current_planning = $this->getPlanningOn($date);
        $return = array();
        if ($current_planning->locked()) {
            $locker = $this->entityManager->getRepository(Agent::class)->find($current_planning->locker_id());
            $return['locker'] = $locker->nom() . ' ' . $locker->prenom();
            $return['locked_on'] = $current_planning->locked_on()->format('d/m/Y H:i');
        }

        return $this->json($return);
    }

    /**
     * @Route("/statedweek", name="statedweek.index", methods={"GET"})
     */
    public function index(Request $request)
    {

        $date = $request->get('date');
        if (!$date) {
            $date = date('Y-m-d');
        }

        $date_pl = new \datePl($date);

        $planning = $this->getPlanningOn($date);

        // Absences
        $a = new \absences();
        $a->valide = false;
        $a->agents_supprimes = array(0,1,2);
        $a->fetch(null, null, $date, $date);
        $absences = $this->filterAgents($a->elements);

        // Holidays
        $c = new \conges();
        $holidays = $this->filterAgents($c->all($date.' 00:00:00', $date.' 23:59:59'));

        $this->templateParams(array(
            'planning'      => $planning,
            'absences'      => $absences,
            'holidays'      => $holidays,
            'date'          => $date,
            'week_number'   => $date_pl->semaine,
            'week_days'     => $date_pl->dates,
            'pause2'        => $this->Config('PlanningHebdo-Pause2') ? 1 : 0,
            'CSRFSession'   => $GLOBALS['CSRFSession'],
        ));
        if ($planning->locked()) {
            $locker = $this->entityManager->getRepository(Agent::class)->find($planning->locker_id());
            $this->templateParams(array('locker' => $locker));
        }

        return $this->output('statedweek/index.html.twig');
    }

    /**
     * @Route("/ajax/statedweek/availables", name="statedweek.availables", methods={"POST"})
     */
    public function availableAgent(Request $request)
    {
        $date = $request->get('date');
        $from = $date . ' ' . $request->get('from');
        $to = $date . ' ' . $request->get('to');
        $job_name = $request->get('job_name');

        $availables = array();
        $agents = $this->entityManager
            ->getRepository(Agent::class)
            ->findBy(array(
                'supprime'  => 0,
                'service'   => $this->config('statedweek_service_filter')
            ));

        $required_skills = array();
        $jobs_conf = $this->config('statedweek_times_job');
        if ($job_name) {
            foreach ($jobs_conf as $job_conf) {
                if ($job_conf['name'] == $job_name) {
                    $required_skills = $job_conf['skills'];
                }
            }
        }

        foreach ($agents as $agent) {

            if ($agent->id() == 1 || $agent->id() == 2) {
                continue;
            }

            if (!$agent->isInSite($this->config('statedweek_site_filter'))) {
                continue;
            }

            if (!$agent->hasSkills($required_skills)) {
                continue;
            }

            $available = array(
                'fullname'          => $agent->nom() . ' ' . $agent->prenom(),
                'id'                => $agent->id(),
                'absent'            => 0,
                'partially_absent'  => 0,
                'holiday'           => 0,
                'status'            => strtolower(removeAccents(str_replace(' ', '_', $agent->statut()))),
            );

            if ($agent->isAbsentOn($from, $to)) {
                $available['absent'] = 1;
            }

            if ($absences = $agent->isPartiallyAbsentOn($from, $to)) {
                $available['absent'] = 0;
                $absence_times = array();
                foreach ($absences as $absence) {
                    $start = substr($absence['from'], -8);
                    $end = substr($absence['to'], -8);
                    $absence_times[] = array('from' => heure3($start), 'to' => heure3($end));
                }
                $available['partially_absent'] = $absence_times;
            }

            if ($agent->isOnVacationOn($from, $to)) {
                $available['holiday'] = 1;
            }

            $availables[] = $available;
        }

        return $this->json($availables);
    }

    /**
     * @Route("/ajax/statedweekpause/add", name="statedweekpause.add", methods={"POST"})
     */
    public function addPause(Request $request)
    {
        $response = new Response();

        $agent_id = $request->get('agent_id');
        $date = $request->get('date');

        $agent = $this->entityManager->getRepository(Agent::class)->find($agent_id);
        if (!$agent) {
            $response->setContent('Agent not found');
            $response->setStatusCode(404);
            return $response;
        }

        $planning = $this->getPlanningOn($date);
        if (!$planning) {
            $response->setContent('Planning not found');
            $response->setStatusCode(404);
            return $response;
        }

        $pause = new StatedWeekPause();
        $pause->agent_id($agent_id);

        $planning->addPause($pause);

        $this->entityManager->persist($planning);
        $this->entityManager->flush();

        $response->setContent('Pause added');
        $response->setStatusCode(200);
        return $response;
    }

    /**
     * @Route("/ajax/statedweekpause/remove", name="statedweekpause.remove", methods={"POST"})
     */
    public function removePause(Request $request)
    {
        $response = new Response();

        $agent_id = $request->get('agent_id');
        $date = $request->get('date');

        $agent = $this->entityManager->getRepository(Agent::class)->find($agent_id);
        if (!$agent) {
            $response->setContent('Agent not found');
            $response->setStatusCode(404);
            return $response;
        }

        $planning = $this->getPlanningOn($date);
        if (!$planning) {
            $response->setContent('Planning not found');
            $response->setStatusCode(404);
            return $response;
        }

        $planning_id = $planning->id();
        $pause = $this->entityManager
            ->getRepository(StatedWeekPause::class)
            ->findOneBy(array('agent_id' => $agent_id, 'planning_id' => $planning_id));

        $this->entityManager->remove($pause);
        $this->entityManager->flush();

        $response->setContent('Pause deleted');
        $response->setStatusCode(200);
        return $response;
    }

    /**
     * @Route("/ajax/statedweekjob/add", name="statedweekjob.add", methods={"POST"})
     */
    public function addJobHours(Request $request)
    {
        $response = new Response();

        $agent_id = $request->get('agent_id');
        $date = $request->get('date');
        $job_name = $request->get('job_name');
        $from = \DateTime::createFromFormat('H:i', $request->get('from'));
        $to = \DateTime::createFromFormat('H:i', $request->get('to'));
        $break = \DateTime::createFromFormat('H:i', $request->get('breaktime'));

        $from = $from ? $from : null;
        $to = $to ? $to : null;
        $break = $break ? $break : null;

        $agent = $this->entityManager->getRepository(Agent::class)->find($agent_id);
        if (!$agent) {
            $response->setContent('Agent not found');
            $response->setStatusCode(404);
            return $response;
        }

        $job = $this->getJob($date, $job_name);
        if (!$job) {
            $response->setContent('Job not found');
            $response->setStatusCode(404);
            return $response;
        }

        $job_id = $job->id();

        $times = new StatedWeekJobTimes();
        $times->agent_id($agent->id());
        $times->job_id($job->id());
        $times->starttime($from);
        $times->endtime($to);
        $times->breaktime($break);

        $this->entityManager->persist($times);
        $this->entityManager->flush();
        $id = $times->id();

        return $this->json($id);
    }

    /**
     * @Route("/ajax/statedweekjob/update", name="statedweekjob.update", methods={"GET", "POST"})
     */
    public function updateJobHours(Request $request)
    {
        $response = new Response();

        $time_id = $request->get('jobtimeid');
        $from = \DateTime::createFromFormat('H:i', $request->get('from'));
        $to = \DateTime::createFromFormat('H:i', $request->get('to'));
        $break = \DateTime::createFromFormat('H:i', $request->get('breaktime'));
        $date = $request->get('date');

        $from = $from ? $from : null;
        $to = $to ? $to : null;
        $break = $break ? $break : null;

        $job_agent = $this->entityManager
            ->getRepository(StatedWeekJobTimes::class)
            ->find($time_id);

        if (!$job_agent) {
            $response->setContent('Time not found');
            $response->setStatusCode(404);
            return $response;
        }

        $job_agent->starttime($from);
        $job_agent->endtime($to);
        $job_agent->breaktime($break);

        $this->entityManager->persist($job_agent);
        $this->entityManager->flush();

        $return = array('absence' => 0);
        $from = $from->format('H:i:s');
        $to = $to->format('H:i:s');
        $agent = $this->entityManager->getRepository(Agent::class)->find($job_agent->agent_id());
        if ($absences = $agent->isPartiallyAbsentOn("$date $from", "$date $to")) {
            $return['absence'] = 1;
            $absence_times = array();
            foreach ($absences as $absence) {
                $start = substr($absence['from'], -8);
                $end = substr($absence['to'], -8);
                $absence_times[] = array('from' => heure3($start), 'to' => heure3($end));
            }
            $return['partially_absent'] = $absence_times;
        }

        return $this->json($return);
    }

    /**
     * @Route("/ajax/statedweek/add", name="statedweek.add", methods={"POST"})
     */
    public function addWorkingHours(Request $request)
    {
        $response = new Response();

        $agent_id = $request->get('agent_id');
        $from = $request->get('from');
        $to = $request->get('to');
        $date = $request->get('date');

        $agent = $this->entityManager->getRepository(Agent::class)->find($agent_id);
        if (!$agent) {
            $response->setContent('Agent not found');
            $response->setStatusCode(404);
            return $response;
        }

        $column = $this->getColumn($date, $from, $to);
        if (!$column) {
            $response->setContent('Planning times not found');
            $response->setStatusCode(404);
            return $response;
        }

        $column_id = $column->id();

        $times = new StatedWeekTimes();
        $times->agent_id($agent->id());
        $times->column_id($column->id());

        $this->entityManager->persist($times);
        $this->entityManager->flush();

        $response->setContent('Working hours updated');
        $response->setStatusCode(200);
        return $response;
    }

    /**
     * @Route("/ajax/statedweekjob/remove", name="statedweekjob.remove", methods={"POST"})
     */
    public function removeJobHours(Request $request)
    {
        $response = new Response();

        $agent_id = $request->get('agent_id');
        $job_name = $request->get('job_name');
        $date = $request->get('date');

        $agent = $this->entityManager->getRepository(Agent::class)->find($agent_id);
        if (!$agent) {
            $response->setContent('Agent not found');
            $response->setStatusCode(404);
            return $response;
        }

        $job = $this->getJob($date, $job_name);
        if (!$job) {
            $response->setContent('Job not found');
            $response->setStatusCode(404);
            return $response;
        }

        $job_id = $job->id();

        $job_agent = $this->entityManager
            ->getRepository(StatedWeekJobTimes::class)
            ->findOneBy(array('agent_id' => $agent_id, 'job_id' => $job_id));

        if (!$job_agent) {
            $response->setContent('Time not found');
            $response->setStatusCode(404);
            return $response;
        }

        $this->entityManager->remove($job_agent);
        $this->entityManager->flush();

        $response->setContent('job hours deleted');
        $response->setStatusCode(200);
        return $response;
    }

    /**
     * @Route("/ajax/statedweek/remove", name="statedweek.remove", methods={"POST"})
     */
    public function removeWorkingHours(Request $request)
    {
        $response = new Response();

        $agent_id = $request->get('agent_id');
        $date = $request->get('date');

        $columns = $this->getColumns($date);
        $column_ids = array();
        foreach ($columns as $column) {
            $column_ids[] = $column->id();
        }

        $agent = $this->entityManager
            ->getRepository(StatedWeekTimes::class)
            ->findOneBy(array(
                'agent_id'  => $agent_id,
                'column_id' => $column_ids
            ));

        if (!$agent) {
            $response->setContent('Agent not found');
            $response->setStatusCode(404);
            return $response;
        }

        $this->entityManager->remove($agent);
        $this->entityManager->flush();

        $response->setContent('Working hours deleted');
        $response->setStatusCode(200);
        return $response;
    }

    /**
     * @Route("/ajax/statedweek/placed", name="statedweek.placed", methods={"GET", "POST"})
     */
    public function placedWorkingHours(Request $request)
    {
        $date = $request->get('date');

        $planning = $this->getPlanningOn($date);

        if (!$planning) {
            $response->setContent('Planning not found');
            $response->setStatusCode(404);
            return $response;
        }

        $placed = array();
        foreach ($planning->columns() as $column) {
            $from = $column->starttime()->format('H:i:s');
            $to = $column->endtime()->format('H:i:s');

            $times = $this->entityManager
                ->getRepository(StatedWeekTimes::class)
                ->findBy(array('column_id' => $column->id()));

            foreach ($times as $t) {
                $agent = $this->entityManager->getRepository(Agent::class)->find($t->agent_id());

                $p = array(
                    'place'     => 'planning',
                    'id'        => $agent->id(),
                    'name'      => $agent->nom() . ' ' .$agent->prenom(),
                    'from'      => $from,
                    'to'        => $to,
                    'absent'    => $agent->isAbsentOn($date, $date) ? 1 : 0,
                    'status'    => strtolower(removeAccents(str_replace(' ', '_', $agent->statut()))),
                );

                if ($absences = $agent->isPartiallyAbsentOn("$date $from", "$date $to")) {
                    $p['absent'] = 0;
                    $absence_times = array();
                    foreach ($absences as $absence) {
                        $start = substr($absence['from'], -8);
                        $end = substr($absence['to'], -8);
                        $absence_times[] = array('from' => heure3($start), 'to' => heure3($end));
                    }
                    $p['partially_absent'] = $absence_times;
                }

                if ($agent->isOnVacationOn("$date $from", "$date $to")) {
                    $p['holiday'] = 1;
                }

                $placed[] = $p;
            }
        }

        foreach ($planning->jobs() as $job) {
            $times = $this->entityManager
                ->getRepository(StatedWeekJobTimes::class)
                ->findBy(array('job_id' => $job->id()));

            foreach ($times as $t) {
                $agent = $this->entityManager->getRepository(Agent::class)->find($t->agent_id());
                $from = $t->starttime() ? $t->starttime()->format('H:i') : '';
                $to = $t->endtime() ? $t->endtime()->format('H:i') : '';
                $break = $t->breaktime() ? $t->breaktime()->format('H:i') : '';

                $p = array(
                    'place'     => 'job',
                    'id'        => $agent->id(),
                    'jobtimeid'    => $t->id(),
                    'name'      => $agent->nom() . ' ' .$agent->prenom(),
                    'job_name'  => $job->name(),
                    'from'      => $from,
                    'to'        => $to,
                    'breaktime' => $break,
                    'absent'    => $agent->isAbsentOn($date, $date) ? 1 : 0,
                    'status'    => strtolower(removeAccents(str_replace(' ', '_', $agent->statut()))),
                );

                if ($absences = $agent->isPartiallyAbsentOn("$date $from", "$date $to")) {
                    $p['absent'] = 0;
                    $absence_times = array();
                    foreach ($absences as $absence) {
                        $start = substr($absence['from'], -8);
                        $end = substr($absence['to'], -8);
                        $absence_times[] = array('from' => heure3($start), 'to' => heure3($end));
                    }
                    $p['partially_absent'] = $absence_times;
                }

                if ($agent->isOnVacationOn("$date $from", "$date $to")) {
                    $p['holiday'] = 1;
                }

                $placed[] = $p;
            }
        }

        foreach ($planning->pauses() as $pause) {
            $agent = $this->entityManager->getRepository(Agent::class)->find($pause->agent_id());

                $placed[] = array(
                    'place'     => 'pause',
                    'id'        => $agent->id(),
                    'name'      => $agent->nom() . ' ' .$agent->prenom(),
                    'status'    => strtolower(removeAccents(str_replace(' ', '_', $agent->statut()))),
                );
        }

        return $this->json($placed);
    }

    /**
     * @Route("/ajax/statedweek/emptyplanning", name="statedweek.empty", methods={"GET", "POST"})
     */
    public function checkWeekPlanning(Request $request)
    {
        $response = new Response();

        $date = $request->get('date');
        if (!$date) {
            $response->setContent('Missing date');
            $response->setStatusCode(400);
            return $response;
        }

        $date_pl = new \datePl($date);
        $dates = $date_pl->dates;
        if (!$this->config('Dimanche')) {
            unset($dates[6]);
        }

        $empty_plannings = array();
        foreach ($dates as $d) {
            $planning = $this->getPlanningOn($d, false);
            if (empty($planning)) {
                $empty_plannings[] = dateAlpha($d);
            }
        }

        return $this->json($empty_plannings);
    }

    private function createPlanning($date) {

        $planning = new StatedWeek();
        $planning->date($date);

        $times_ranges = $this->config('statedweek_times_range');
        foreach ($times_ranges as $range) {
            $from = \DateTime::createFromFormat('H:i:s', $range['from']);
            $to = \DateTime::createFromFormat('H:i:s', $range['to']);

            $column = new StatedWeekColumn();
            $column->starttime($from);
            $column->endtime($to);
            $planning->addColumn($column);
        }

        $jobs = $this->config('statedweek_times_job');
        foreach ($jobs as $j) {
            $job = new StatedWeekJob();
            $job->name($j['name']);
            $job->description($j['description']);
            $planning->addJob($job);
        }

        $this->entityManager->persist($planning);
        $this->entityManager->flush();

        return $planning;
    }

    private function getPlanningOn($date, $create = true)
    {
        $date = \DateTime::createFromFormat('Y-m-d', $date);

        $planning = $this->entityManager
            ->getRepository(StatedWeek::class)
            ->findOneBy(array('date' => $date));

        if (empty($planning) && $create) {
            $planning = $this->createPlanning($date);
        }

        return $planning;
    }

    private function getColumn($date, $from, $to)
    {
        $date = \DateTime::createFromFormat('Y-m-d', $date);
        $planning = $this->entityManager
            ->getRepository(StatedWeek::class)
            ->findOneBy(array('date' => $date));

        if (!$planning) {
            return null;
        }


        $from = \DateTime::createFromFormat('H:i:s', $from);
        $to = \DateTime::createFromFormat('H:i:s', $to);
        $column = $this->entityManager
            ->getRepository(StatedWeekColumn::class)
            ->findOneBy(array(
                'planning_id'   => $planning->id(),
                'starttime'     => $from,
                'endtime'       => $to
            ));

        return $column;
    }

    private function getColumns($date)
    {
        $date = \DateTime::createFromFormat('Y-m-d', $date);
        $planning = $this->entityManager
            ->getRepository(StatedWeek::class)
            ->findOneBy(array('date' => $date));

        if (!$planning) {
            return array();
        }

        return $planning->columns();
    }

    private function getJob($date, $name)
    {
        $date = \DateTime::createFromFormat('Y-m-d', $date);
        $planning = $this->entityManager
            ->getRepository(StatedWeek::class)
            ->findOneBy(array('date' => $date));

        if (!$planning) {
            return null;
        }


        $job = $this->entityManager
            ->getRepository(StatedWeekJob::class)
            ->findOneBy(array(
                'planning_id'   => $planning->id(),
                'name'          => $name
            ));

        return $job;
    }

    private function getJobs($date)
    {
        $date = \DateTime::createFromFormat('Y-m-d', $date);
        $planning = $this->entityManager
            ->getRepository(StatedWeek::class)
            ->findOneBy(array('date' => $date));

        if (!$planning) {
            return array();
        }

        return $planning->jobs();
    }

    private function filterAgents($elements)
    {
        $filtered = array();
        foreach ($elements as $elem) {
            $agent = $this->entityManager->getRepository(Agent::class)->find($elem['perso_id']);

            if ($agent->supprime() == 1) {
                continue;
            }

            if ($agent->service() != $this->config('statedweek_service_filter')) {
                continue;
            }

            if (!$agent->isInSite($this->config('statedweek_site_filter'))) {
                continue;
            }

            $elem['status'] = strtolower(removeAccents(str_replace(' ', '_', $agent->statut())));

            $filtered[] = $elem;
        }

        return $filtered;
    }
}